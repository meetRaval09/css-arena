<?php
/* ────────────────────────────────────────────────────────────────
   Who is at this PC, and what is the event doing right now.
   Every page and every endpoint asks these questions, so they live here.
   ──────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/rank.php';
require_once __DIR__ . '/migrate.php';

/* Cheap after the first run — one file_exists and nothing else. */
ensure_schema();

const ARENA_COOKIE = 'arena_token';

/* ---------- settings ---------- */

function setting($k, $default = null) {
    $r = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $r->execute([$k]);
    $row = $r->fetch();
    return $row ? $row['v'] : $default;
}

function set_setting($k, $v) {
    $u = db()->prepare('UPDATE settings SET v = ? WHERE k = ?');
    $u->execute([(string) $v, $k]);
    if ($u->rowCount() === 0) {
        db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute([$k, (string) $v]);
    }
}

/* ---------- the person at this PC ---------- */

function issue_token() {
    return bin2hex(random_bytes(16));
}

function set_arena_cookie($token) {
    setcookie(ARENA_COOKIE, $token, [
        'expires'  => time() + 86400,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[ARENA_COOKIE] = $token;
}

function clear_arena_cookie() {
    setcookie(ARENA_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
    unset($_COOKIE[ARENA_COOKIE]);
}

/* The participant this browser belongs to, or null. */
function me() {
    static $cached = false;
    if ($cached !== false) return $cached;

    $t = $_COOKIE[ARENA_COOKIE] ?? '';
    if ($t === '' || !preg_match('/^[a-f0-9]{32}$/', $t)) return $cached = null;

    $q = db()->prepare('SELECT * FROM participants WHERE token = ? AND is_active = 1');
    $q->execute([$t]);
    return $cached = ($q->fetch() ?: null);
}

/* Called by every state poll, so the admin roster can show who has gone quiet. */
function touch_participant($id) {
    db()->prepare('UPDATE participants SET last_seen = ? WHERE id = ?')
        ->execute([sql_now(), $id]);
}

/* One place that decides what "now" looks like to the database. */
function sql_now($offsetSeconds = 0) {
    return date('Y-m-d H:i:s', time() + $offsetSeconds);
}

/* ---------- seats ---------- */

/* One machine, one spelling. "lab2-09", "LAB2 09" and "Lab2-09" are the same
   seat, and a student who types it in lower case should not quietly become a
   second occupant of a machine that is already taken. Everything upstream
   compares and stores this form. */
function seat_key($raw) {
    $s = strtoupper(trim(preg_replace('/\s+/u', ' ',
         preg_replace('/[\x00-\x1f\x7f]/u', '', (string) $raw))));
    return $s;
}

/* How many seconds since we last heard from this browser. A row that has
   never polled is treated as very old rather than as present. */
function seen_ago_s($lastSeen) {
    return $lastSeen ? max(0, time() - strtotime($lastSeen)) : PHP_INT_MAX;
}

/* Whoever is sitting at this seat, if anyone — ignoring $exceptId (the person
   asking) and anyone the organiser has released. A released row must NOT hold
   its seat, or handing a machine to another student would be impossible. */
function seat_holder($seatKey, $exceptId = 0) {
    if ($seatKey === '') return null;
    $q = db()->prepare('SELECT id, name, seat_no, last_seen FROM participants
                         WHERE is_active = 1 AND id <> ? AND UPPER(seat_no) = ?');
    $q->execute([(int) $exceptId, $seatKey]);
    return $q->fetch() ?: null;
}

/* Deliberately does not name who is sitting there. The person reading this is
   a stranger who typed somebody else's seat number, and the screen is in a
   hall full of people — their own name is the one thing they do not need to
   see it say. The seat number is echoed back because that is what they typed
   and it is what they have to correct. */
function seat_taken_message($holder) {
    return 'Seat ' . $holder['seat_no'] . ' is already taken. '
         . 'Check the number on your machine, or tell the organiser.';
}

/* ---------- rounds ---------- */

function round_by_no($no) {
    $q = db()->prepare('SELECT * FROM rounds WHERE round_no = ?');
    $q->execute([(int) $no]);
    return $q->fetch() ?: null;
}

/* The round the organiser has on screen. Null before the first one starts. */
function current_round() {
    $no = (int) setting('current_round', 0);
    return $no ? round_by_no($no) : null;
}

function ms_of($datetime) {
    return $datetime ? strtotime($datetime) * 1000 : null;
}

/* How many people the organiser has confirmed for a round. */
function qualifier_count($roundId) {
    $q = db()->prepare('SELECT COUNT(*) FROM qualifiers WHERE round_id = ?');
    $q->execute([$roundId]);
    return (int) $q->fetchColumn();
}

/* Is this participant taking part in round $no?

   Round 1 is everyone. Later rounds are whoever the organiser confirmed —
   but a round with NO confirmed list is open to everyone too. Without that
   rule, starting round 2 before publishing results locks the whole lab out
   of a round that is already running, with no way back in. */
function is_eligible($participantId, $roundNo, $roundId) {
    if ((int) $roundNo <= 1) return true;
    if (qualifier_count($roundId) === 0) return true;
    $q = db()->prepare('SELECT 1 FROM qualifiers WHERE round_id = ? AND participant_id = ?');
    $q->execute([$roundId, $participantId]);
    return (bool) $q->fetchColumn();
}

/* This participant's row for a round, if one exists. */
function submission_for($participantId, $roundId) {
    $q = db()->prepare('SELECT * FROM submissions WHERE participant_id = ? AND round_id = ?');
    $q->execute([$participantId, $roundId]);
    return $q->fetch() ?: null;
}

/* ---------- everyone's in: stop the clock ---------- */

/* How many people this round is actually waiting on.
   Round 1 (or any round with no confirmed list) is every active participant;
   a round with a confirmed list is only those people, and only while they are
   still active — a PC the organiser has released stops holding the round up. */
function expected_count($round) {
    $pdo = db();
    if ((int) $round['round_no'] > 1 && qualifier_count($round['id']) > 0) {
        $q = $pdo->prepare('SELECT COUNT(*) FROM qualifiers q
                              JOIN participants p ON p.id = q.participant_id
                             WHERE q.round_id = ? AND p.is_active = 1');
        $q->execute([$round['id']]);
        return (int) $q->fetchColumn();
    }
    return (int) $pdo->query('SELECT COUNT(*) FROM participants WHERE is_active = 1')->fetchColumn();
}

/* How many of them have handed in a final answer IN THIS RUN of the round.

   The "in this run" part is not fussiness. A round can be started again — after
   a false start, after Reset rounds, after a rehearsal — and last time's
   submissions are still sitting in the table. Counting those would end the new
   round in the same second it began, before anybody had typed a character.
   So only work submitted at or after the current start time counts. */
function final_count($round) {
    if (empty($round['started_at'])) return 0;
    $q = db()->prepare('SELECT COUNT(*) FROM submissions s
                          JOIN participants p ON p.id = s.participant_id
                         WHERE s.round_id = ? AND s.is_final = 1 AND p.is_active = 1
                           AND s.submitted_at IS NOT NULL
                           AND s.submitted_at >= ?');
    $q->execute([$round['id'], $round['started_at']]);
    return (int) $q->fetchColumn();
}

/* Close a round for good. One place, so the organiser's End now button and
   the automatic finish leave the database in exactly the same state. */
function close_round($roundNo) {
    db()->prepare("UPDATE rounds SET status = 'ended', ends_at = ? WHERE round_no = ?")
        ->execute([sql_now(), (int) $roundNo]);
    set_setting('event_state', 'between');
}

/* Waiting for the clock when nobody is still writing is dead time in a hall
   full of people. The moment the last entry is in, the round is over.

   Returns true if this call ended it. Safe to call from anywhere — it does
   nothing unless the round is running and the count actually matches. */
function auto_end_if_done($round = null) {
    $r = $round ?: current_round();
    if (!$r || $r['status'] !== 'running') return false;

    $expected = expected_count($r);
    if ($expected < 1) return false;                 // nobody registered yet
    if (final_count($r) < $expected) return false;   // someone is still working

    close_round($r['round_no']);
    return true;
}
