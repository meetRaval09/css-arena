<?php
/* Live picture of the event for the console. Polled once a second, by one
   browser only, so it can afford to be more detailed than api/state.php. */
require_once __DIR__ . '/auth.php';
require_admin(true);

$pdo = db();
$now = now_ms();

/* Safety net. The round normally closes itself the moment the last entry is
   submitted; if that request died on the way, the console notices here. */
auto_end_if_done();

$r = current_round();

$rounds = [];
foreach ($pdo->query('SELECT * FROM rounds ORDER BY round_no') as $row) {
    $startMs = ms_of($row['started_at']);
    $rounds[] = [
        'no'           => (int) $row['round_no'],
        'difficulty'   => $row['difficulty'],
        'status'       => $row['status'],
        'time_limit_s' => (int) $row['time_limit_s'],
        'qualify_count'=> (int) $row['qualify_count'],
        'chars_first'  => (int) $row['chars_first'],
        'started_ms'   => $startMs,
        'ends_ms'      => $startMs ? deadline_ms($startMs, (int) $row['time_limit_s'], 0) : null,
        'design'       => $row['design_id'] ? 'set' : null,
        'qualifiers'   => qualifier_count((int) $row['id']),
    ];
}

/* one row per participant, with this round's work if there is any */
$sql = 'SELECT p.id, p.name, p.email, p.seat_no, p.last_seen, p.is_active,
               s.char_count, s.is_final, s.status AS sub_status,
               s.extra_time_ms, s.accuracy, s.submitted_at
          FROM participants p
          LEFT JOIN submissions s
                 ON s.participant_id = p.id AND s.round_id = :rid
         WHERE p.is_active = 1
         ORDER BY p.id';
$q = $pdo->prepare($sql);
$q->execute([':rid' => $r ? $r['id'] : 0]);

$people = [];
$scored = 0; $submitted = 0; $errors = 0;
foreach ($q as $row) {
    $seenAgoS = $row['last_seen'] ? max(0, time() - strtotime($row['last_seen'])) : null;
    $state = 'waiting';
    if ($r && $r['status'] === 'running') {
        $state = 'working';
        if ((int) $row['is_final'] === 1) $state = 'submitted';
    }
    if ($seenAgoS === null || $seenAgoS > 15) $state = 'offline';

    if ((int) $row['is_final'] === 1) $submitted++;
    if ($row['sub_status'] === 'scored') $scored++;
    if ($row['sub_status'] === 'error')  $errors++;

    $people[] = [
        'id'        => (int) $row['id'],
        'name'      => $row['name'],
        'email'     => $row['email'],
        'seat'      => $row['seat_no'],
        'chars'     => $row['char_count'] === null ? null : (int) $row['char_count'],
        'state'     => $state,
        'seen_ago'  => $seenAgoS,
        'credit_ms' => (int) $row['extra_time_ms'],
        'accuracy'  => $row['accuracy'] === null ? null : (float) $row['accuracy'],
    ];
}

json_out([
    'ok'      => true,
    'now'     => $now,
    'event'   => setting('event_state', 'lobby'),
    'current' => $r ? (int) $r['round_no'] : 0,
    'rounds'  => $rounds,
    'people'  => $people,
    'counts'  => [
        'registered' => count($people),
        'online'     => count(array_filter($people, function ($x) { return $x['state'] !== 'offline'; })),
        'submitted'  => $submitted,
        'scored'     => $scored,
        'errors'     => $errors,
    ],
]);
