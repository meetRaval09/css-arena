<?php
/* ────────────────────────────────────────────────────────────────
   The front door. One page, two states:
     · no cookie  -> registration
     · cookie     -> the waiting room, polling until the organiser starts

   Every child PC is pointed at this URL and left alone for the whole event.
   ──────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/lib/state.php';

/* index.php?leave  — forget this browser and go back to registration.
   Used to hand a PC to a different student, and to reset a test. */
if (isset($_GET['leave'])) {
    /* Let go of the liveness claim as well as the cookie.

       Registration refuses an email whose browser is still polling, which is
       what stops one student taking over another. Leaving is a deliberate
       "this browser is no longer that person", so the claim should drop at
       once — otherwise a student who presses this by accident is locked out
       of their own email for the next twenty seconds.

       Their row and their saved code are untouched; only the heartbeat is
       cleared, so they can type their email again straight away. */
    $leaving = me();
    if ($leaving) {
        db()->prepare('UPDATE participants SET last_seen = NULL WHERE id = ?')
            ->execute([$leaving['id']]);
    }
    clear_arena_cookie();
    header('Location: index.php');
    exit;
}

/* never let a browser show a cached copy of this page */
header('Cache-Control: no-store, must-revalidate');

$p = me();

/* Already playing? Skip the lobby entirely. */
if ($p) {
    $r = current_round();
    if ($r && $r['status'] === 'running' && is_eligible($p['id'], $r['round_no'], $r['id'])) {
        $sub = submission_for($p['id'], $r['id']);
        header('Location: ' . (($sub && (int) $sub['is_final'] === 1) ? 'thanks.php' : 'play.php'));
        exit;
    }
}

$PAGE_TITLE = 'CSS Arena — VivaCity 2K26';
include __DIR__ . '/partials/head.php';
?>

<!-- ══════════════ registration ══════════════ -->
<section class="screen<?= $p ? '' : ' on' ?>" id="s-register">
  <div class="hero">
    <div class="hero-l">
      <div class="lockup">
        <div class="logo" data-logo="112">CB</div>
        <div class="lockup-t">
          <span class="lk-name">CSS Arena</span>
          <span class="lk-sub">VivaCity 2K26 · Tech Fest</span>
        </div>
      </div>

      <h1 class="hero-h">Recreate the design.<br><span>Pixel for pixel.</span></h1>
      <p class="hero-p">Write HTML and CSS until your render matches the target.
         Every entry is drawn by the same browser and compared pixel by pixel.</p>

      <?php /* A tiny worked example: the code on the left becomes the shape on
               the right. It types itself once on load, then stands still. */ ?>
      <div class="demo" aria-hidden="true">
        <div class="demo-code"><span class="demo-bar"><i></i><i></i><i></i></span><pre id="demoCode"></pre></div>
        <div class="demo-out"><div class="demo-shape" id="demoShape"></div><span class="demo-acc" id="demoAcc">—</span></div>
      </div>

      <ol class="rounds">
        <li><b>01</b><span>Easy</span><em>Accuracy</em></li>
        <li><b>02</b><span>Intermediate</span><em>Accuracy</em></li>
        <li><b>03</b><span>Final</span><em>Accuracy · characters</em></li>
      </ol>
    </div>

    <div class="hero-r">
      <!-- novalidate: we show our own messages in one place rather than
       letting the browser pop its own tooltip for the email field -->
      <form id="regForm" class="regcard" autocomplete="off" novalidate>
        <div class="rc-head">
          <b>Register</b>
          <span><i class="livedot"></i>Local server · online</span>
        </div>
        <div class="field">
          <label for="rn">Your name</label>
          <input id="rn" name="name" placeholder="e.g. Aarav Mehta" autocomplete="off" maxlength="40">
        </div>
        <div class="field">
          <label for="re">Email</label>
          <input id="re" name="email" type="email" placeholder="e.g. aarav@college.edu"
                 autocomplete="off" maxlength="120" inputmode="email">
        </div>
        <div class="field two">
          <div>
            <label for="rp">Phone number</label>
            <input id="rp" name="phone" type="tel" placeholder="98765 43210"
                   autocomplete="off" maxlength="20" inputmode="tel">
          </div>
          <div>
            <label for="rs">PC / seat no.</label>
            <input id="rs" name="seat" placeholder="LAB2-14" autocomplete="off" maxlength="20">
          </div>
        </div>
        <div class="reghint" id="regHint"></div>
        <button class="btn" type="submit" id="regGo">Enter the arena <span class="arr">→</span></button>
        <p class="rc-foot">One entry per person. Your seat number must match the label on this PC.</p>
      </form>
    </div>
  </div>
</section>

<!-- ══════════════ waiting room ══════════════ -->
<section class="screen<?= $p ? ' on' : '' ?>" id="s-wait">
  <div class="wait">
    <div class="waitlogo" data-logo="96"></div>
    <div class="pulse"></div>
    <h2>You're in, <span id="waitName"><?= htmlspecialchars($p['name'] ?? 'friend') ?></span>.</h2>
    <p class="mut">Waiting for the organiser to start <b style="color:var(--tx)" id="waitRound">the next round</b>.<br>
       Keep this screen open — it will switch by itself.</p>
    <div class="card" style="margin-top:30px;padding:16px 20px;display:inline-flex;gap:28px">
      <div><div class="num" id="regCount" style="font-size:19px;font-weight:600">—</div><div class="k dim" style="font:600 9px/1 var(--mono);letter-spacing:.13em;margin-top:5px">REGISTERED</div></div>
      <div><div class="num" id="waitSeat" style="font-size:19px;font-weight:600">—</div><div class="k dim" style="font:600 9px/1 var(--mono);letter-spacing:.13em;margin-top:5px">YOUR SEAT</div></div>
      <div><div class="num" id="waitLen" style="font-size:19px;font-weight:600">—</div><div class="k dim" id="waitLenLbl" style="font:600 9px/1 var(--mono);letter-spacing:.13em;margin-top:5px">ROUND LENGTH</div></div>
    </div>
    <div id="waitNote" class="dim" style="margin-top:26px;font-size:12px"></div>
    <div class="roster" id="roster"></div>
  </div>
</section>

<div class="cdown" id="cdown">
  <div class="n" id="cdnum">3</div>
  <div class="lb" id="cdlb">ROUND STARTS</div>
</div>

<script src="<?= asset('assets/js/brand.js') ?>"></script>
<script src="<?= asset('assets/js/lobby.js') ?>"></script>
<script src="<?= asset('assets/js/demo.js') ?>"></script>
</body>
</html>
