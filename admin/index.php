<?php
require_once __DIR__ . '/auth.php';
require_admin();

if (isset($_GET['logout'])) {
    admin_session_start();
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

/* the address the child PCs should be pointed at */
$hostIp = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
$lanIp  = $hostIp;
foreach (@gethostbynamel(gethostname()) ?: [] as $ip) {
    if (strpos($ip, '127.') !== 0) { $lanIp = $ip; break; }
}

$PAGE_TITLE = 'CSS Arena — admin console';
$BASE = '..';
include __DIR__ . '/../partials/head.php';
?>
<section class="screen on">
  <div class="wrap">
    <div class="brand" style="margin-bottom:26px">
      <div class="logo" data-logo="42">A</div>
      <div>
        <h1>Admin console</h1>
        <p>Organiser only · <span id="hdCount">—</span> connected ·
           <a href="?logout" style="color:var(--dim);text-decoration:none;border-bottom:1px solid var(--line)">lock</a></p>
      </div>
    </div>

    <div class="agrid">
      <div>
        <div class="sect">Rounds</div>
        <div id="roundCards"></div>

        <div class="sect">Live roster</div>
        <div class="card" style="padding:14px 4px 4px">
          <table class="live">
            <thead><tr><th>Participant</th><th>Seat</th><th>Chars</th><th>Status</th><th>Time credit</th><th></th></tr></thead>
            <tbody id="live"></tbody>
          </table>
        </div>
      </div>

      <div>
        <div class="sect">This round</div>
        <div class="card" style="padding:18px">
          <div style="display:flex;justify-content:space-between;font-size:13px">
            <b id="qLabel">Nothing running</b><span class="dim" id="qErr"></span>
          </div>
          <div class="qbar"><i id="qFill" style="width:0"></i></div>
          <p class="dim" style="font-size:11.5px;margin:0 0 12px;line-height:1.6" id="qNote">
            Start a round below and every waiting PC will count down together.
          </p>
          <button class="btn sm" id="scoreBtn" style="display:none;width:100%">Score this round</button>
          <a class="btn sm ghost" id="resultsBtn" href="../results.php"
             style="display:none;width:100%;text-align:center;text-decoration:none;margin-top:8px">Standings</a>
        </div>

        <div class="sect">Server</div>
        <div class="card" style="padding:6px 18px">
          <div class="kv"><span>Participant URL</span><b><?= htmlspecialchars($lanIp) ?>/vivacity</b></div>
          <div class="kv"><span>Registered</span><b id="svReg">—</b></div>
          <div class="kv"><span>Online now</span><b id="svOn">—</b></div>
          <div class="kv"><span>Renderer</span><b style="color:var(--ok)">chrome-headless-shell</b></div>
        </div>

        <div class="sect">Actions</div>
        <div class="card" style="padding:16px;display:flex;flex-direction:column;gap:8px">
          <a class="btn sm" href="designs.php" style="text-align:center;text-decoration:none">Round designs</a>

          <?php /* A plain download link on purpose — no JavaScript, so it cannot
                   interfere with the polling console, and it only ever reads. */ ?>
          <a class="btn sm ghost" href="export.php"
             style="text-align:center;text-decoration:none">Export Excel record</a>
          <p class="dim" style="font:11px/1.5 var(--sans);margin:-2px 0 6px">
            Participants, all three rounds and a summary, in one workbook. Safe to
            press at any time — it only reads.
          </p>

          <button class="btn danger sm" id="btnReset">Reset rounds</button>
          <button class="btn danger sm" id="btnFullReset">Full reset — delete everyone</button>
        </div>
      </div>
    </div>
  </div>
</section>

<script>var ADMIN_TOKEN = <?= json_encode(admin_token()) ?>;</script>
<script src="<?= asset('assets/js/brand.js', '..') ?>"></script>
<script src="<?= asset('assets/js/admin.js', '..') ?>"></script>
</body>
</html>
