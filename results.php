<?php
/* ────────────────────────────────────────────────────────────────
   The standings. Organiser's PC only — participants never see this, by
   design: the results are read out, not looked up.

   Nothing here decides anything. The ranking comes from lib/rank.php, which
   is the single place the evaluation rules live.
   ──────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/admin/auth.php';
require_admin();
require_once __DIR__ . '/lib/rank.php';

$pdo = db();
$roundNo = (int) ($_GET['round'] ?? 0);
$r = $roundNo ? round_by_no($roundNo) : current_round();
if (!$r) { $r = round_by_no(1); }

$q = $pdo->prepare('SELECT s.*, p.name, p.email, p.phone, p.seat_no
                      FROM submissions s
                      JOIN participants p ON p.id = s.participant_id
                     WHERE s.round_id = ? AND s.is_final = 1');
$q->execute([$r['id']]);
$all = $q->fetchAll();

$scored = array_values(array_filter($all, function ($x) { return $x['status'] === 'scored'; }));
$broken = array_values(array_filter($all, function ($x) { return $x['status'] !== 'scored'; }));

foreach ($scored as &$s) { $s['accuracy'] = (float) $s['accuracy']; $s['char_count'] = (int) $s['char_count']; $s['elapsed_ms'] = (int) $s['elapsed_ms']; }
unset($s);

$ranked = rank_round($scored, (int) $r['chars_first'] === 1, (int) $r['qualify_count']);
$passing = count(array_filter($ranked, function ($x) { return $x['qualified']; }));

/* CSV for the record */
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="round' . (int) $r['round_no'] . '-results.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Place', 'Participant', 'Email', 'Phone', 'Seat', 'Accuracy %',
                   'Characters', 'Time', 'Qualified']);
    foreach ($ranked as $x) {
        fputcsv($out, [$x['place'], $x['name'], $x['email'], $x['phone'], $x['seat_no'],
                       number_format($x['accuracy'], 2),
                       $x['char_count'], gmdate('i:s', (int) ($x['elapsed_ms'] / 1000)),
                       $x['qualified'] ? 'yes' : 'no']);
    }
    foreach ($broken as $x) {
        fputcsv($out, ['—', $x['name'], $x['email'], $x['phone'], $x['seat_no'],
                       'not scored', $x['char_count'], '', $x['status']]);
    }
    exit;
}

function mmss_ms($ms) { return sprintf('%d:%02d', floor($ms / 60000), floor(($ms % 60000) / 1000)); }

$PAGE_TITLE = 'Round ' . $r['round_no'] . ' standings — CSS Arena';
include __DIR__ . '/partials/head.php';
?>
<section class="screen on">
  <div class="wrap">
    <div class="brand" style="margin-bottom:20px">
      <div class="lbmark" data-logo="64"></div>
      <div style="margin-left:4px">
        <h1>Round <?= (int) $r['round_no'] ?> standings</h1>
        <p><?= count($ranked) ?> scored<?= $broken ? ' · ' . count($broken) . ' not scored' : '' ?> ·
           <?= (int) $r['qualify_count']
                 ? $passing . ' go through to Round ' . ((int) $r['round_no'] + 1)
                 : 'final round' ?> ·
           <a href="?round=<?= (int) $r['round_no'] ?>&csv=1" style="color:var(--dim);text-decoration:none;border-bottom:1px solid var(--line)">CSV</a> ·
           <a href="admin/index.php" style="color:var(--dim);text-decoration:none;border-bottom:1px solid var(--line)">console</a></p>
      </div>
    </div>
<?php
/* The commonest confusion on the day: the round is over, the table is open,
   and every row says "queued" — because nothing has been scored yet. The
   fix is one button press, so say which button. */
$waiting = count(array_filter($broken, function ($x) { return $x['status'] !== 'error'; }));
if ($waiting): ?>
    <div class="card" style="margin-bottom:18px;padding:14px 18px;border-left:3px solid var(--gold)">
      <b><?= $waiting ?> <?= $waiting === 1 ? 'entry is' : 'entries are' ?> not scored yet.</b>
      <span class="mut">Open the <a href="admin/index.php" style="color:var(--gold)">console</a>
      and press <b>Score this round</b> — the accuracy figures appear here as each one finishes.</span>
    </div>
<?php endif; ?>

    <div style="display:flex;gap:8px;margin-bottom:16px;align-items:center">
      <?php foreach ([1, 2, 3] as $n): ?>
        <a class="btn sm <?= $n === (int) $r['round_no'] ? '' : 'ghost' ?>" href="?round=<?= $n ?>"
           style="text-decoration:none">Round <?= $n ?></a>
      <?php endforeach; ?>

      <?php /* The way back. On the night the organiser bounces between this
               table and the console every few minutes, and the small "console"
               link in the sub-heading is not something you can hit in a hurry
               in a dark hall. This is. */ ?>
      <a class="btn sm ghost" href="admin/index.php"
         style="text-decoration:none;margin-left:auto">&larr; Back to console</a>
    </div>

    <div class="card" style="padding:14px 4px 4px">
      <table class="live" style="width:100%">
        <thead><tr>
          <th style="width:60px">Place</th><th>Participant</th><th>Email</th><th>Seat</th>
          <th>Accuracy</th>
          <?php if ((int) $r['chars_first'] === 1): ?><th>Characters</th><th>Time</th><?php endif; ?>
          <th><?= (int) $r['qualify_count'] ? 'Qualified' : 'Result' ?></th>
        </tr></thead>
        <tbody>
        <?php if (!$ranked): ?>
          <tr><td colspan="8" class="dim" style="padding:18px">
            Nothing scored yet. Finish the round, then press <b>Score this round</b> on the console.
          </td></tr>
        <?php endif; ?>
        <?php foreach ($ranked as $x): ?>
          <tr<?= $x['qualified'] ? ' style="background:#101a14"' : '' ?>>
            <td style="font-family:var(--mono);<?= $x['place'] <= 3 ? 'color:var(--gold);font-weight:700' : '' ?>">
              <?= (int) $x['place'] ?></td>
            <td><?= htmlspecialchars($x['name']) ?></td>
            <td class="dim" style="font-family:var(--mono);font-size:11.5px"><?= htmlspecialchars($x['email']) ?></td>
            <td class="dim"><?= htmlspecialchars($x['seat_no'] ?: '—') ?></td>
            <td style="font-family:var(--mono)"><?= number_format($x['accuracy'], 2) ?>%</td>
            <?php if ((int) $r['chars_first'] === 1): ?>
              <td style="font-family:var(--mono)"><?= (int) $x['char_count'] ?></td>
              <td class="dim" style="font-family:var(--mono)"><?= mmss_ms((int) $x['elapsed_ms']) ?></td>
            <?php endif; ?>
            <td>
              <?php if ((int) $r['qualify_count']): ?>
                <?= $x['qualified']
                      ? '<span style="color:var(--ok)">through</span>'
                      : '<span class="dim">out</span>' ?>
              <?php else: ?>
                <?= $x['place'] === 1 ? '<span style="color:var(--gold)">winner</span>' : '<span class="dim">—</span>' ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php foreach ($broken as $x): ?>
          <tr>
            <td class="dim">—</td>
            <td><?= htmlspecialchars($x['name']) ?></td>
            <td class="dim" style="font-family:var(--mono);font-size:11.5px"><?= htmlspecialchars($x['email']) ?></td>
            <td class="dim"><?= htmlspecialchars($x['seat_no'] ?: '—') ?></td>
            <td colspan="4" style="color:var(--bad);font-size:12px">
              <?= htmlspecialchars($x['status'] === 'error' ? ($x['error_msg'] ?: 'error') : $x['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ((int) $r['qualify_count'] && $ranked): ?>
      <div class="card" style="padding:18px;margin-top:16px">
        <b><?= $passing ?> qualify for Round <?= (int) $r['round_no'] + 1 ?></b>
        <p class="dim" style="font-size:12px;line-height:1.7;margin:8px 0 14px">
          Top <?= (int) $r['qualify_count'] ?> by accuracy, plus anyone tied with the last qualifying place,
          and nobody below <?= ACCURACY_FLOOR ?>%. Confirming writes the list down — until you do,
          Round <?= (int) $r['round_no'] + 1 ?> is open to everyone.
        </p>
        <form method="post" action="admin/action.php">
          <input type="hidden" name="token" value="<?= htmlspecialchars(admin_token()) ?>">
          <input type="hidden" name="do" value="confirm_qualifiers">
          <input type="hidden" name="round" value="<?= (int) $r['round_no'] ?>">
          <button class="btn" type="submit">Confirm these <?= $passing ?> for Round <?= (int) $r['round_no'] + 1 ?></button>
        </form>
      </div>
    <?php endif; ?>

    <p class="dim" style="font-size:11.5px;margin-top:22px;line-height:1.8">
      The best design wins — time is a limit, not a score. Accuracy is rounded to <?= TIE_BAND ?>%
      before ranking, which is about twice the noise floor of the renderer and well under the cost of a
      one-pixel slip, so two entries only tie when they really are the same.
      <?= (int) $r['chars_first'] === 1
            ? 'In this round a tie on accuracy is broken by fewer characters, then by earlier submission.'
            : 'Equal accuracy is an equal place — characters and time are not counted in this round.' ?>
    </p>
  </div>
</section>
<script src="<?= asset('assets/js/brand.js') ?>"></script>
</body>
</html>
