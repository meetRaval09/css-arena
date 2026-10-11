<?php
/* ────────────────────────────────────────────────────────────────
   Everything the organiser can actually do. One endpoint, one action per
   call, every one of them locked to this PC and to a logged-in session.
   ──────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/auth.php';
require_admin(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'POST only'], 405);
if (!check_token($_POST['token'] ?? ''))   json_out(['ok' => false, 'error' => 'stale page — reload'], 403);

/* Seconds between pressing Start and the round actually beginning. Every PC
   counts down to the same instant, so nobody gets a head start. */
const LEAD_IN_S = 5;

$do  = (string) ($_POST['do'] ?? '');
$pdo = db();

switch ($do) {

case 'start_round':
    $no = (int) ($_POST['round'] ?? 0);
    $r  = round_by_no($no);
    if (!$r) json_out(['ok' => false, 'error' => 'no such round']);

    $limit = (int) ($_POST['time_limit_s'] ?? $r['time_limit_s']);
    if ($limit < 60 || $limit > 7200) $limit = (int) $r['time_limit_s'];

    /* any other round that is still open gets closed first */
    $pdo->prepare("UPDATE rounds SET status = 'ended' WHERE status = 'running' AND round_no <> ?")
        ->execute([$no]);

    $startAt = sql_now(LEAD_IN_S);
    $pdo->prepare("UPDATE rounds
                      SET status = 'running', started_at = ?, ends_at = ?, time_limit_s = ?
                    WHERE round_no = ?")
        ->execute([$startAt, sql_now(LEAD_IN_S + $limit), $limit, $no]);

    set_setting('current_round', $no);
    set_setting('event_state', 'running');
    json_out(['ok' => true, 'starts_in' => LEAD_IN_S]);

case 'end_round':
    close_round((int) ($_POST['round'] ?? 0));
    json_out(['ok' => true]);

case 'extra_time':
    /* A PC that died gets more working time — never a score adjustment.
       Only this participant's own deadline moves. */
    $pid  = (int) ($_POST['participant_id'] ?? 0);
    $mins = (int) ($_POST['minutes'] ?? 0);
    if ($mins < 1 || $mins > 30) json_out(['ok' => false, 'error' => 'between 1 and 30 minutes']);
    $r = current_round();
    if (!$r) json_out(['ok' => false, 'error' => 'no round running']);

    $ms = $mins * 60000;
    $u  = $pdo->prepare('UPDATE submissions SET extra_time_ms = extra_time_ms + ?
                          WHERE participant_id = ? AND round_id = ?');
    $u->execute([$ms, $pid, $r['id']]);
    if ($u->rowCount() === 0) {
        $pdo->prepare("INSERT INTO submissions (participant_id, round_id, code, extra_time_ms)
                       VALUES (?, ?, '', ?)")
            ->execute([$pid, $r['id'], $ms]);
    }
    json_out(['ok' => true]);

case 'drop_participant':
    $pid = (int) ($_POST['participant_id'] ?? 0);
    $pdo->prepare('UPDATE participants SET is_active = 0 WHERE id = ?')->execute([$pid]);
    /* If that PC was the only one the round was still waiting on, the round
       is now complete and should stop by itself. */
    auto_end_if_done();
    json_out(['ok' => true, 'round_ended' => current_round() && current_round()['status'] === 'ended']);

case 'confirm_qualifiers':
    /* Write down who goes through. Until this is done the next round is open
       to everyone, which is what stops a mis-timed Start from locking the lab
       out of a round that is already running. */
    require_once __DIR__ . '/../lib/rank.php';
    $no   = (int) ($_POST['round'] ?? 0);
    $from = round_by_no($no);
    $to   = round_by_no($no + 1);
    if (!$from || !$to) json_out(['ok' => false, 'error' => 'no round after that one']);

    $q = $pdo->prepare("SELECT s.participant_id, s.accuracy, s.char_count, s.elapsed_ms
                          FROM submissions s
                         WHERE s.round_id = ? AND s.is_final = 1 AND s.status = 'scored'");
    $q->execute([$from['id']]);
    $rows = $q->fetchAll();
    foreach ($rows as &$x) {
        $x['accuracy']   = (float) $x['accuracy'];
        $x['char_count'] = (int) $x['char_count'];
        $x['elapsed_ms'] = (int) $x['elapsed_ms'];
    }
    unset($x);

    $ranked = rank_round($rows, (int) $from['chars_first'] === 1, (int) $from['qualify_count']);
    $through = array_values(array_filter($ranked, function ($x) { return $x['qualified']; }));

    $pdo->prepare('DELETE FROM qualifiers WHERE round_id = ?')->execute([$to['id']]);
    $ins = $pdo->prepare('INSERT INTO qualifiers (round_id, participant_id) VALUES (?, ?)');
    foreach ($through as $x) $ins->execute([$to['id'], $x['participant_id']]);

    if (empty($_POST['ajax'])) { header('Location: ../results.php?round=' . $no); exit; }
    json_out(['ok' => true, 'qualified' => count($through)]);

case 'rescore':
    /* put everything in this round back in the queue */
    $no = (int) ($_POST['round'] ?? 0);
    $rr = round_by_no($no);
    if (!$rr) json_out(['ok' => false, 'error' => 'no such round']);
    $only = !empty($_POST['errors_only']);
    $sql  = "UPDATE submissions SET status = 'queued', error_msg = NULL
              WHERE round_id = ? AND is_final = 1" . ($only ? " AND status = 'error'" : '');
    $pdo->prepare($sql)->execute([$rr['id']]);
    json_out(['ok' => true]);

case 'reset_rounds':
    /* Back to the lobby. Participants and their work are kept. */
    $pdo->exec("UPDATE rounds SET status = 'pending', started_at = NULL, ends_at = NULL, published = 0");
    set_setting('current_round', '0');
    set_setting('event_state', 'lobby');
    json_out(['ok' => true]);

case 'full_reset':
    /* Everything the event produced goes. Used between a rehearsal and the
       real thing.

       The rounds also let go of their designs, so nothing shows as live —
       but the designs themselves are KEPT. They are work the organiser did
       before the event, not event data, and they stay in the library ready
       to be attached again. Re-attach all three before starting Round 1. */
    $pdo->exec('DELETE FROM qualifiers');
    $pdo->exec('DELETE FROM submissions');
    $pdo->exec('DELETE FROM participants');
    $pdo->exec("UPDATE rounds SET status = 'pending', started_at = NULL, ends_at = NULL,
                                  published = 0, design_id = NULL");
    set_setting('current_round', '0');
    set_setting('event_state', 'lobby');
    json_out(['ok' => true]);

default:
    json_out(['ok' => false, 'error' => 'unknown action']);
}
