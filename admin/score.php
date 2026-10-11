<?php
/* ────────────────────────────────────────────────────────────────
   Scores one queued submission and reports what is left.

   The console calls this a few times in parallel until the queue is empty,
   so the organiser watches a progress bar instead of a terminal window.
   Each call takes one job under a lock, so two callers never render the
   same entry twice.
   ──────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/auth.php';
require_admin(true);
require_once __DIR__ . '/../lib/shoot.php';
require_once __DIR__ . '/../lib/diff.php';
require_once __DIR__ . '/../lib/sanitize.php';

@set_time_limit(120);
ensure_storage();

$pdo = db();
$roundNo = (int) ($_POST['round'] ?? $_GET['round'] ?? 0);
$r = $roundNo ? round_by_no($roundNo) : current_round();
if (!$r) json_out(['ok' => false, 'error' => 'no such round']);

function queue_counts($pdo, $roundId) {
    $q = $pdo->prepare("SELECT
            SUM(status = 'queued')  AS queued,
            SUM(status = 'scoring') AS scoring,
            SUM(status = 'scored')  AS scored,
            SUM(status = 'error')   AS errors,
            COUNT(*)                AS total
          FROM submissions WHERE round_id = ? AND is_final = 1");
    $q->execute([$roundId]);
    $c = $q->fetch();
    return array_map('intval', [
        'queued' => $c['queued'], 'scoring' => $c['scoring'],
        'scored' => $c['scored'], 'errors' => $c['errors'], 'total' => $c['total'],
    ]);
}

/* Reclaim abandoned jobs. A scoring request can die halfway — the browser
   is closed, PHP hits its time limit, antivirus stalls the render — and the
   row would otherwise sit in 'scoring' for ever and never be picked up
   again, stranding somebody's entry. Anything claimed more than two minutes
   ago and still unfinished goes back in the queue. */
$pdo->prepare("UPDATE submissions SET status = 'queued'
                WHERE round_id = ? AND status = 'scoring' AND updated_at < ?")
    ->execute([$r['id'], date('Y-m-d H:i:s', time() - 120)]);

/* claim one job — the UPDATE is the lock */
$pick = $pdo->prepare("SELECT id FROM submissions
                        WHERE round_id = ? AND is_final = 1 AND status = 'queued'
                        ORDER BY id LIMIT 1");
$pick->execute([$r['id']]);
$id = $pick->fetchColumn();

if (!$id) json_out(['ok' => true, 'done' => true] + queue_counts($pdo, $r['id']));

$claim = $pdo->prepare("UPDATE submissions SET status = 'scoring', updated_at = ?
                         WHERE id = ? AND status = 'queued'");
$claim->execute([sql_now(), $id]);
if ($claim->rowCount() === 0) {
    json_out(['ok' => true, 'skipped' => true] + queue_counts($pdo, $r['id']));   // someone else took it
}

$row = $pdo->query('SELECT * FROM submissions WHERE id = ' . (int) $id)->fetch();

/* the design this round was run with */
$d = $pdo->prepare('SELECT * FROM designs WHERE id = ?');
$d->execute([$r['design_id']]);
$design = $d->fetch();

function fail($pdo, $id, $msg, $r) {
    $pdo->prepare("UPDATE submissions SET status = 'error', error_msg = ? WHERE id = ?")
        ->execute([substr($msg, 0, 250), $id]);
    json_out(['ok' => true, 'scored' => false, 'error_msg' => $msg] + queue_counts($pdo, $r['id']));
}

if (!$design)                       fail($pdo, $id, 'no design attached to this round', $r);
$target = ROOT . '/' . $design['png_path'];
if (!is_file($target))              fail($pdo, $id, 'the design image is missing', $r);

$problem = code_problem($row['code']);
if ($problem)                       fail($pdo, $id, $problem, $r);

$png = STORAGE . '/shots/sub-' . (int) $r['round_no'] . '-' . (int) $row['participant_id'] . '.png';
list($ok, $msg) = shoot($row['code'], $png);
if (!$ok)                           fail($pdo, $id, 'render failed: ' . $msg, $r);

$cmp = compare_png($target, $png);
if (!$cmp)                          fail($pdo, $id, 'comparison failed', $r);

$acc = rescale($cmp['raw'], (float) $design['blank_raw']);

$pdo->prepare("UPDATE submissions
                  SET accuracy = ?, raw_score = ?, exact_pct = ?, shot_path = ?,
                      status = 'scored', error_msg = NULL
                WHERE id = ?")
    ->execute([round($acc, 3), round($cmp['raw'], 3), round($cmp['exact'], 3),
               'storage/shots/' . basename($png), $id]);

json_out(['ok' => true, 'scored' => true, 'accuracy' => round($acc, 2)]
         + queue_counts($pdo, $r['id']));
