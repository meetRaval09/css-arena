<?php
/* ────────────────────────────────────────────────────────────────
   "Check accuracy" — renders the participant's saved draft through the same
   headless browser and the same comparison that will score the final entry,
   so the number on screen is the number that counts.

   The host PC has to survive sixty people pressing this, so two guards:
   a per-person cooldown, and a cap on how many renders run at once. Anyone
   over the cap waits their turn rather than piling another browser on.
   ──────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/../lib/state.php';
require_once __DIR__ . '/../lib/sanitize.php';
require_once __DIR__ . '/../lib/shoot.php';
require_once __DIR__ . '/../lib/diff.php';

json_errors();

@set_time_limit(60);

const CHECK_COOLDOWN_S = 14;   // per participant
const CHECK_WAIT_S     = 20;   // how long to queue for a free slot

$p = me();
if (!$p) json_out(['ok' => false, 'error' => 'not registered'], 401);

$r = current_round();
if (!$r || $r['status'] !== 'running') json_out(['ok' => false, 'error' => 'no round running']);
if (!is_eligible($p['id'], $r['round_no'], $r['id'])) json_out(['ok' => false, 'error' => 'not in this round']);
if (!$r['design_id']) json_out(['ok' => false, 'error' => 'no design attached to this round']);

$q = db()->prepare('SELECT * FROM designs WHERE id = ?');
$q->execute([$r['design_id']]);
$design = $q->fetch();
if (!$design) json_out(['ok' => false, 'error' => 'the design is missing']);

$target = ROOT . '/' . $design['png_path'];
if (!is_file($target)) json_out(['ok' => false, 'error' => 'the design image is missing']);

$sub = submission_for($p['id'], $r['id']);
if (!$sub || $sub['code'] === '') json_out(['ok' => false, 'error' => 'write something first']);
if ((int) $sub['is_final'] === 1)  json_out(['ok' => false, 'error' => 'already submitted']);

$problem = code_problem($sub['code']);
if ($problem) json_out(['ok' => false, 'error' => $problem]);

/* ---- cooldown, kept on disk so it survives between requests ---- */
ensure_storage();
$stamp = STORAGE . '/render/check-' . (int) $p['id'] . '.txt';
$prev  = is_file($stamp) ? (int) file_get_contents($stamp) : 0;
if (time() - $prev < CHECK_COOLDOWN_S) {
    json_out(['ok' => false, 'error' => 'One check every 15 seconds. Nearly there.']);
}

/* ---- one of a small number of slots, or wait ---- */
$slot = null; $fh = null;
$deadline = microtime(true) + CHECK_WAIT_S;
while ($slot === null && microtime(true) < $deadline) {
    for ($i = 0; $i < SCORE_WORKERS; $i++) {
        $f = @fopen(STORAGE . '/render/slot-' . $i . '.lock', 'c');
        if ($f && flock($f, LOCK_EX | LOCK_NB)) { $slot = $i; $fh = $f; break; }
        if ($f) fclose($f);
    }
    if ($slot === null) usleep(250000);
}
if ($slot === null) json_out(['ok' => false, 'error' => 'The scorer is busy. Try again in a few seconds.']);

file_put_contents($stamp, time());

$png = STORAGE . '/shots/check-' . (int) $p['id'] . '.png';
list($ok, $msg) = shoot($sub['code'], $png);

flock($fh, LOCK_UN); fclose($fh);

if (!$ok) json_out(['ok' => false, 'error' => 'Could not render that. ' . $msg]);

$cmp = compare_png($target, $png);
if (!$cmp) json_out(['ok' => false, 'error' => 'Could not compare the images.']);

$acc = rescale($cmp['raw'], (float) $design['blank_raw']);

json_out([
    'ok'       => true,
    'accuracy' => round($acc, 2),
    'raw'      => round($cmp['raw'], 2),
    'exact'    => round($cmp['exact'], 1),
    'off'      => (int) $cmp['off'],
]);
