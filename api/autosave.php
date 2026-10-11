<?php
/* A draft. Called every few seconds by every arena, so it stays small:
   one indexed upsert and nothing else. */
require_once __DIR__ . '/../lib/state.php';
require_once __DIR__ . '/../lib/sanitize.php';

json_errors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'POST only'], 405);

$p = me();
if (!$p) json_out(['ok' => false, 'error' => 'not registered'], 401);

$r = current_round();
if (!$r || $r['status'] !== 'running') json_out(['ok' => false, 'error' => 'no round running']);
if (!is_eligible($p['id'], $r['round_no'], $r['id'])) json_out(['ok' => false, 'error' => 'not in this round']);

$code = (string) ($_POST['code'] ?? '');
if (strlen($code) > 200000) json_out(['ok' => false, 'error' => 'that is far too long']);

/* the console works out who is alive from this — the arena does not poll
   the lobby endpoint often enough to be the only signal */
touch_participant($p['id']);

$chars = countable_length($code);
$sub   = submission_for($p['id'], $r['id']);

if ($sub) {
    if ((int) $sub['is_final'] === 1) json_out(['ok' => false, 'error' => 'already submitted']);
    db()->prepare('UPDATE submissions SET code = ?, char_count = ? WHERE id = ?')
        ->execute([$code, $chars, $sub['id']]);
    $extra = (int) $sub['extra_time_ms'];
} else {
    db()->prepare('INSERT INTO submissions (participant_id, round_id, code, char_count)
                   VALUES (?, ?, ?, ?)')
        ->execute([$p['id'], $r['id'], $code, $chars]);
    $extra = 0;
}

json_out([
    'ok'      => true,
    'chars'   => $chars,
    'ends_ms' => deadline_ms(ms_of($r['started_at']), (int) $r['time_limit_s'], $extra),
]);
