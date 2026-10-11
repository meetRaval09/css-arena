<?php
/* ────────────────────────────────────────────────────────────────
   POST name, email, phone, seat  ->  a participant row and a cookie.

   Two people must never end up sharing one row. That happened in the lab:
   two students typed the same email, and the second one silently took over
   the first one's registration — the first was pushed out of the waiting room
   without either of them being told anything.

   The rule now:

     · email  identifies a PERSON and is unique
     · seat   identifies a MACHINE and is unique among people still in
     · a returning student is one whose email AND seat both match, or whose
       old session has gone quiet — anything else is a different person and
       is refused, with a message that says what to do

   The reconnect path has to survive, because it is the recovery plan for a
   crashed PC: reopen, type the same email, get your code back. So it is
   narrowed rather than removed.
   ──────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/../lib/state.php';

json_errors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'POST only'], 405);
}

/* How long a registration has to be silent before we believe that browser is
   really gone. The state poll writes last_seen every 5 seconds, so 20 means
   four missed polls. The admin console calls someone offline at 15. */
const SEAT_STALE_S = 20;

function clean($v) {
    return preg_replace('/\s+/u', ' ',
           trim(preg_replace('/[\x00-\x1f\x7f]/u', '', (string) $v)));
}

$name  = clean($_POST['name']  ?? '');
$email = strtolower(clean($_POST['email'] ?? ''));
$phone = clean($_POST['phone'] ?? '');
$seat  = seat_key($_POST['seat'] ?? '');

/* ---- what we insist on ---- */
if (mb_strlen($name) < 2)
    json_out(['ok' => false, 'error' => 'Please type your name.']);
if (mb_strlen($name) > 40)
    json_out(['ok' => false, 'error' => 'That name is too long — 40 characters at most.']);

if ($email === '')
    json_out(['ok' => false, 'error' => 'Please type your email address.']);
if (mb_strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL))
    json_out(['ok' => false, 'error' => 'That email address does not look right.']);

$phoneDigits = preg_replace('/[^0-9]/', '', $phone);
if ($phoneDigits === '')
    json_out(['ok' => false, 'error' => 'Please type your phone number.']);
if (strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15)
    json_out(['ok' => false, 'error' => 'That phone number does not look right.']);
if (mb_strlen($phone) > 20) $phone = mb_substr($phone, 0, 20);

/* The seat is no longer optional. It is how we tell a student coming back to
   their own PC apart from a different student typing the same email. */
if ($seat === '')
    json_out(['ok' => false, 'error' => 'Please type your PC / seat number — it is on the machine.']);
if (mb_strlen($seat) > 20) $seat = mb_substr($seat, 0, 20);

/* Registration closes once the organiser starts round 2. Round 1 is open all
   the way through, so a latecomer can still join while it is running. */
if ((int) setting('current_round', 0) > 1) {
    json_out(['ok' => false, 'error' => 'Registration has closed for this event.']);
}

$pdo = db();

/* ---- is this email already somebody? ---- */
$q = $pdo->prepare('SELECT * FROM participants WHERE email = ?');
$q->execute([$email]);
$row = $q->fetch();

if ($row) {
    /* Is that registration still alive? That is the ONLY question worth
       asking here.

       An earlier version also accepted "same email AND same seat" as proof of
       the same person, on the theory that a second student would at least be
       sitting somewhere else. Two PCs both typing seat 1 broke it: the second
       registration was handed the first one's row and its cookie, both
       browsers became the same participant, and the first student watched his
       own name change to somebody else's. A typed field can never prove
       identity when both people can type the same thing.

       Liveness can't be typed. If a browser is still polling, that person is
       still there, and nobody else gets their row — whatever they type. */
    $quietFor = seen_ago_s($row['last_seen']);

    if ((int) $row['is_active'] === 1 && $quietFor < SEAT_STALE_S) {
        json_out(['ok' => false, 'error' =>
            'That email is already registered at seat ' . ($row['seat_no'] ?: '?')
          . ' and is in use right now. Use your own email address — or, if that '
          . 'is you coming back, wait half a minute and try again.']);
    }

    /* Gone quiet: a crashed browser, a cleared cookie, or a move to a spare
       machine. All genuine returns. The seat they are claiming still has to be
       free — somebody may have taken their old one. */
    $clash = seat_holder($seat, (int) $row['id']);
    if ($clash) json_out(['ok' => false, 'error' => seat_taken_message($clash)]);

    $pdo->prepare('UPDATE participants
                      SET name = ?, phone = ?, seat_no = ?, is_active = 1, last_seen = ?
                    WHERE id = ?')
        ->execute([$name, $phone !== '' ? $phone : $row['phone'],
                   $seat, sql_now(), $row['id']]);
    set_arena_cookie($row['token']);
    json_out(['ok' => true, 'reconnected' => true, 'name' => $name]);
}

/* ---- a new person: the seat must be free ---- */
$clash = seat_holder($seat, 0);
if ($clash) json_out(['ok' => false, 'error' => seat_taken_message($clash)]);

$token = issue_token();
try {
    $pdo->prepare('INSERT INTO participants (name, email, phone, seat_no, token, last_seen)
                   VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$name, $email, $phone !== '' ? $phone : null, $seat, $token, sql_now()]);
} catch (PDOException $e) {
    /* Something refused the insert. Do not assume which rule it was — look.
       If the email really is taken, two PCs raced and the other one won. */
    $again = $pdo->prepare('SELECT * FROM participants WHERE email = ?');
    $again->execute([$email]);
    $other = $again->fetch();

    if ($other) {
        json_out(['ok' => false, 'error' =>
            'That email was just registered at seat ' . ($other['seat_no'] ?: '?')
          . '. Please use your own email address.']);
    }
    json_out(['ok' => false, 'error' => 'The database refused that registration: '
                                      . $e->getMessage()]);
}

set_arena_cookie($token);
json_out(['ok' => true, 'reconnected' => false, 'name' => $name]);
