<?php
/* ────────────────────────────────────────────────────────────────
   The organiser's record of the event, as one Excel workbook.

   Behind require_admin(), so the .htaccess IP lock, the $ADMIN_IPS check and
   the password all apply — a child PC cannot reach this and cannot read the
   participants' contact details.

   It only READS. Nothing in this file writes to the database, so pressing the
   button at the wrong moment — mid-round, before anyone has registered, twice
   in a row — cannot affect the competition. Worst case you get a workbook
   with headers and no rows.

   The ranking is not recalculated here. It comes from rank_round(), the same
   function the standings page uses, so the spreadsheet and the screen can
   never disagree about who won.
   ──────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/../lib/rank.php';
require_once __DIR__ . '/../lib/xlsx.php';

@set_time_limit(120);

$pdo = db();

function mmss($ms) {
    $ms = (int) $ms;
    return $ms > 0 ? sprintf('%d:%02d', floor($ms / 60000), floor(($ms % 60000) / 1000)) : '';
}
function human($dt) { return $dt ? date('d M Y, H:i', strtotime($dt)) : ''; }

/* ---------- every round, ranked exactly as the standings page ranks it ---------- */

$rounds = [];
foreach ($pdo->query('SELECT * FROM rounds ORDER BY round_no') as $row) {
    $q = $pdo->prepare('SELECT s.*, p.name, p.email, p.phone, p.seat_no, p.is_active
                          FROM submissions s
                          JOIN participants p ON p.id = s.participant_id
                         WHERE s.round_id = ? AND s.is_final = 1');
    $q->execute([$row['id']]);
    $all = $q->fetchAll();

    $scored = array_values(array_filter($all, function ($x) { return $x['status'] === 'scored'; }));
    $broken = array_values(array_filter($all, function ($x) { return $x['status'] !== 'scored'; }));

    foreach ($scored as &$s) {
        $s['accuracy']   = (float) $s['accuracy'];
        $s['char_count'] = (int) $s['char_count'];
        $s['elapsed_ms'] = (int) $s['elapsed_ms'];
    }
    unset($s);

    $rounds[(int) $row['round_no']] = [
        'row'    => $row,
        'ranked' => rank_round($scored, (int) $row['chars_first'] === 1, (int) $row['qualify_count']),
        'broken' => $broken,
    ];
}

/* ---------- what each participant did across the whole event ---------- */

$people = $pdo->query('SELECT * FROM participants ORDER BY id')->fetchAll();

$best = [];      // participant_id => best accuracy seen
$played = [];    // participant_id => [round numbers]
$furthest = [];  // participant_id => highest round they submitted in
foreach ($rounds as $no => $R) {
    foreach (array_merge($R['ranked'], $R['broken']) as $x) {
        $pid = (int) $x['participant_id'];
        $played[$pid][] = $no;
        $furthest[$pid] = max($furthest[$pid] ?? 0, $no);
        if ($x['status'] === 'scored') {
            $best[$pid] = max($best[$pid] ?? 0, (float) $x['accuracy']);
        }
    }
}

$book = new Xlsx();

/* ---------- sheet 1: summary ---------- */

$final    = $rounds[3]['ranked'] ?? [];
$champion = $final && $final[0]['place'] === 1 ? $final[0] : null;

$sum = [];
$sum[] = ['Event',              'CSS Arena — VivaCity 2K26'];
$sum[] = ['Record exported',    date('d M Y, H:i')];
$sum[] = '';
$sum[] = ['Registered',         count($people)];
$sum[] = ['Still active',       count(array_filter($people, function ($p) { return (int) $p['is_active'] === 1; }))];
$sum[] = ['Released',           count(array_filter($people, function ($p) { return (int) $p['is_active'] !== 1; }))];
$sum[] = '';

foreach ([1, 2, 3] as $no) {
    if (!isset($rounds[$no])) continue;
    $R = $rounds[$no];
    $through = count(array_filter($R['ranked'], function ($x) { return $x['qualified']; }));
    $sum[] = ['Round ' . $no . ' (' . $R['row']['difficulty'] . ')',
              $R['row']['status'] === 'pending' ? 'not held' : ucfirst($R['row']['status'])];
    $sum[] = ['   entries',        count($R['ranked']) + count($R['broken'])];
    $sum[] = ['   scored',         count($R['ranked'])];
    if ((int) $R['row']['qualify_count']) $sum[] = ['   went through', $through];
    $sum[] = '';
}

if ($champion) {
    $sum[] = ['WINNER', $champion['name']];
    $sum[] = ['   email',    $champion['email']];
    $sum[] = ['   seat',     $champion['seat_no']];
    $sum[] = ['   accuracy', number_format($champion['accuracy'], 2) . '%'];
    $sum[] = ['   characters', (int) $champion['char_count']];
    $sum[] = '';
    $top = array_slice($final, 0, 3);
    foreach ($top as $t) $sum[] = ['Place ' . $t['place'], $t['name'] . '  (' . $t['email'] . ')'];
} else {
    $sum[] = ['WINNER', 'the final round has not been scored yet'];
}

$sum[] = '';
$sum[] = ['__note' => 'The best design wins — time is a limit, not a score. '
                    . 'Rounds 1 and 2 rank on accuracy alone; in Round 3 a tie on '
                    . 'accuracy is broken by fewer characters, then by earlier submission.'];

$book->sheet('Summary', [['Item', 26, 'text'], ['Value', 46, 'text']], $sum);

/* ---------- sheet 2: participants ---------- */

$prows = [];
$i = 0;
foreach ($people as $p) {
    $pid = (int) $p['id'];
    $i++;
    $prows[] = [
        $i,
        $p['name'],
        $p['email'],
        /* text, not a number — see the note in lib/xlsx.php */
        $p['phone'],
        $p['seat_no'],
        human($p['created_at']),
        isset($played[$pid]) ? implode(', ', array_unique($played[$pid])) : '',
        isset($best[$pid]) ? round($best[$pid], 2) : null,
        isset($furthest[$pid]) ? 'Round ' . $furthest[$pid] : 'did not compete',
        (int) $p['is_active'] === 1 ? 'active' : 'released',
    ];
}
if (!$prows) $prows[] = ['__note' => 'Nobody has registered yet.'];

$book->sheet('Participants', [
    ['#', 5, 'num'], ['Name', 22, 'text'], ['Email', 30, 'text'], ['Phone', 16, 'text'],
    ['Seat', 10, 'text'], ['Registered at', 18, 'text'], ['Rounds played', 14, 'text'],
    ['Best accuracy', 14, 'pct'], ['Furthest round', 16, 'text'], ['Status', 11, 'text'],
], $prows);

/* ---------- sheets 3-5: one per round ---------- */

foreach ([1, 2, 3] as $no) {
    if (!isset($rounds[$no])) continue;
    $R    = $rounds[$no];
    $rows = [];

    foreach ($R['ranked'] as $x) {
        $rows[] = [
            $x['place'], $x['name'], $x['email'], $x['seat_no'],
            round((float) $x['accuracy'], 2),
            (int) $x['char_count'],
            mmss($x['elapsed_ms']),
            (int) $R['row']['qualify_count'] ? ($x['qualified'] ? 'yes' : 'no')
                                             : ($x['place'] === 1 ? 'WINNER' : ''),
        ];
    }

    /* Not scored, but they did hand something in. A missing name in an
       official record is worse than an honest blank, so they are listed. */
    if ($R['broken']) {
        $rows[] = '';
        $rows[] = ['__note' => 'Entered but not scored:'];
        foreach ($R['broken'] as $x) {
            $rows[] = ['—', $x['name'], $x['email'], $x['seat_no'], null,
                       (int) $x['char_count'], mmss($x['elapsed_ms']), $x['status']];
        }
    }

    if (!$rows) {
        $rows[] = ['__note' => $R['row']['status'] === 'pending'
            ? 'This round has not been held yet.'
            : 'No entries were scored in this round.'];
    }

    $book->sheet('Round ' . $no, [
        ['Place', 7, 'num'], ['Name', 22, 'text'], ['Email', 30, 'text'], ['Seat', 10, 'text'],
        ['Accuracy', 11, 'pct'], ['Characters', 12, 'num'], ['Time taken', 12, 'text'],
        [(int) $R['row']['qualify_count'] ? 'Qualified' : 'Result', 12, 'text'],
    ], $rows);
}

/* ---------- write it, then hand it over ---------- */

$name = 'CSS-Arena-VivaCity2K26-record-' . date('Y-m-d-Hi') . '.xlsx';
$tmp  = sys_get_temp_dir() . '/' . $name;

list($ok, $msg) = $book->save($tmp);

if (!$ok) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<body style="font:15px system-ui;background:#140f22;color:#f4f1ff;padding:40px">'
       . '<h2>The Excel export could not be written.</h2><p>' . htmlspecialchars($msg) . '</p>'
       . '<p>The per-round CSV links on the standings page still work — use those.</p>'
       . '<p><a style="color:#c9f24a" href="index.php">back to the console</a></p>';
    exit;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
@unlink($tmp);
