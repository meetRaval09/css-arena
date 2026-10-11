<?php
/* ────────────────────────────────────────────────────────────────
   The heartbeat. Every PC in the lab calls this once a second, so it has to
   stay cheap: a handful of indexed reads and one throttled write.

   It is also the only clock in the system. Every countdown on every screen is
   drawn from `now`, so a PC whose Windows clock is wrong still ends on time.
   ──────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/../lib/state.php';

$now  = now_ms();
$p    = me();
$evt  = setting('event_state', 'lobby');
$r    = current_round();

$out = [
    'ok'    => true,
    'now'   => $now,
    'event' => $evt,
    'me'    => null,
    'round' => null,
    'go'    => null,
];

if (!$p) {
    /* unknown browser — send it back to the front door */
    $out['go'] = 'index.php';
    json_out($out);
}

/* last_seen, but only every 5 seconds — 60 PCs polling every second would
   otherwise be 60 writes a second for no benefit. */
db()->prepare('UPDATE participants SET last_seen = ?
               WHERE id = ? AND (last_seen IS NULL OR last_seen < ?)')
    ->execute([sql_now(), $p['id'], sql_now(-5)]);

$out['me'] = ['name' => $p['name'], 'seat' => $p['seat_no']];

/* head count, and the roster — only while people are waiting, so we are not
   shipping 60 names a second to 60 PCs during a live round */
$out['count'] = (int) db()->query('SELECT COUNT(*) FROM participants WHERE is_active = 1')->fetchColumn();

$playing = $r && $r['status'] === 'running';

if (!$playing) {
    $rows = db()->query('SELECT name, seat_no FROM participants
                         WHERE is_active = 1 ORDER BY id DESC LIMIT 80')->fetchAll();
    $out['roster'] = array_map(function ($x) use ($p) {
        return ['name' => $x['name'], 'seat' => $x['seat_no'], 'me' => $x['name'] === $p['name']];
    }, $rows);
}

if ($r) {
    $eligible = is_eligible($p['id'], $r['round_no'], $r['id']);
    $sub      = submission_for($p['id'], $r['id']);
    $extra    = $sub ? (int) $sub['extra_time_ms'] : 0;
    $startMs  = ms_of($r['started_at']);

    $out['round'] = [
        'no'           => (int) $r['round_no'],
        'difficulty'   => $r['difficulty'],
        'status'       => $r['status'],
        'time_limit_s' => (int) $r['time_limit_s'],
        'started_ms'   => $startMs,
        'ends_ms'      => $startMs ? deadline_ms($startMs, (int) $r['time_limit_s'], $extra) : null,
        'eligible'     => $eligible,
        'submitted'    => $sub ? (int) $sub['is_final'] === 1 : false,
    ];

    if ($playing && $eligible) {
        $out['go'] = $out['round']['submitted'] ? 'thanks.php' : 'play.php';
    }
}

json_out($out);
