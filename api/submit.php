<?php
/* The final answer. Saves the code and puts it in the scoring queue —
   the actual rendering happens in bin/scorer.php, away from the request. */
require_once __DIR__ . '/../lib/state.php';
require_once __DIR__ . '/../lib/sanitize.php';

json_errors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'POST only'], 405);

$p = me();
if (!$p) json_out(['ok' => false, 'error' => 'not registered'], 401);

$r = current_round();
if (!$r) json_out(['ok' => false, 'error' => 'no round']);
if (!is_eligible($p['id'], $r['round_no'], $r['id'])) json_out(['ok' => false, 'error' => 'not in this round']);

$sub   = submission_for($p['id'], $r['id']);
$extra = $sub ? (int) $sub['extra_time_ms'] : 0;

$startMs = ms_of($r['started_at']);
$endMs   = deadline_ms($startMs, (int) $r['time_limit_s'], $extra);
$now     = now_ms();

/* A submission that arrives a moment after the bell still counts — the click
   happened in time, the network did not. Anything later is refused. */
if ($now > $endMs + SUBMIT_GRACE_MS) json_out(['ok' => false, 'error' => 'the round has finished']);
if ($sub && (int) $sub['is_final'] === 1) json_out(['ok' => true, 'already' => true]);

$code    = (string) ($_POST['code'] ?? '');
$elapsed = max(0, min($now, $endMs) - $startMs);
$chars   = countable_length($code);
$problem = code_problem($code);

if ($sub) {
    db()->prepare("UPDATE submissions
                      SET code = ?, char_count = ?, is_final = 1, submitted_at = ?,
                          elapsed_ms = ?, status = ?, error_msg = ?
                    WHERE id = ?")
        ->execute([$code, $chars, sql_now(), $elapsed,
                   $problem ? 'error' : 'queued', $problem, $sub['id']]);
} else {
    db()->prepare("INSERT INTO submissions
                     (participant_id, round_id, code, char_count, is_final,
                      submitted_at, elapsed_ms, status, error_msg)
                   VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)")
        ->execute([$p['id'], $r['id'], $code, $chars, sql_now(), $elapsed,
                   $problem ? 'error' : 'queued', $problem]);
}

/* If that was the last person still writing, the round is finished — there is
   no point making a hall full of people watch an empty clock run down. */
$allIn = auto_end_if_done($r);

json_out(['ok' => true, 'chars' => $chars, 'elapsed_ms' => $elapsed,
          'problem' => $problem, 'round_ended' => $allIn]);
