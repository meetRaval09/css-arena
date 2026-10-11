<?php
/* After submitting. Deliberately shows no score and no placing — the
   organiser reads the results out, participants never see them here. */
require_once __DIR__ . '/lib/state.php';
header('Cache-Control: no-store');

$p = me();
if (!$p) { header('Location: index.php'); exit; }
$r   = current_round();
$sub = $r ? submission_for($p['id'], $r['id']) : null;

$PAGE_TITLE = 'Thanks for playing — CSS Arena';
include __DIR__ . '/partials/head.php';
?>
<section class="screen on">
  <div class="wait" style="padding-top:13vh">
    <div class="waitlogo" data-logo="96"></div>
    <h2 style="margin-top:20px">Thanks for playing, <?= htmlspecialchars($p['name']) ?>.</h2>
    <p class="mut">Your work is in.<br>Sit tight — the organiser will call out who goes through.</p>
    <div class="card" style="margin-top:30px;padding:16px 20px;display:inline-flex;gap:28px">
      <div><div class="num" style="font-size:19px;font-weight:600"><?= $sub ? (int) $sub['char_count'] : 0 ?></div>
           <div class="k dim" style="font:600 9px/1 var(--mono);letter-spacing:.13em;margin-top:5px">CHARACTERS</div></div>
      <div><div class="num" style="font-size:19px;font-weight:600"><?= $sub && $sub['elapsed_ms']
              ? floor($sub['elapsed_ms'] / 60000) . ':' . str_pad(floor(($sub['elapsed_ms'] % 60000) / 1000), 2, '0', STR_PAD_LEFT)
              : '—' ?></div>
           <div class="k dim" style="font:600 9px/1 var(--mono);letter-spacing:.13em;margin-top:5px">TIME TAKEN</div></div>
      <div><div class="num" style="font-size:19px;font-weight:600">Round <?= $r ? (int) $r['round_no'] : '—' ?></div>
           <div class="k dim" style="font:600 9px/1 var(--mono);letter-spacing:.13em;margin-top:5px">FINISHED</div></div>
    </div>
    <p class="dim" style="margin-top:26px;font-size:12px">This screen will move on by itself when the next round starts.</p>
  </div>
</section>
<script src="<?= asset('assets/js/brand.js') ?>"></script>
<script src="<?= asset('assets/js/confetti.js') ?>"></script>
<script>
/* They just handed in work they fought for. Say so. */
confetti({ count: 200, duration: 4400 });
setTimeout(function () { confetti({ count: 90, duration: 3200 }); }, 850);

/* Wait here until the organiser starts the next round.

   Note what this does NOT do: bounce the participant out when the round
   stops. The round now ends by itself the moment the last person submits,
   and throwing the finisher back to the front door a second later would
   look like the machine had crashed. They leave when there is somewhere
   new to go. */
setInterval(function () {
  fetch('api/state.php', { cache: 'no-store' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d.go === 'play.php')  { window.location.href = 'play.php';  return; }
      if (d.go === 'index.php') { window.location.href = 'index.php'; return; }
      /* a full reset wipes the event back to the lobby — then they do go back */
      if (d.event === 'lobby')  window.location.href = 'index.php';
    })
    .catch(function () {});
}, 2000);
</script>
</body>
</html>
