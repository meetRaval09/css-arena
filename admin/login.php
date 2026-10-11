<?php
require_once __DIR__ . '/auth.php';

/* the IP bolt applies to the login page too */
if (!ip_allowed()) { require_admin(); }

admin_session_start();
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = (string) ($_POST['password'] ?? '');
    /* a small delay makes guessing at the keyboard pointless */
    usleep(400000);
    if (password_verify($pw, ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['admin_ok'] = true;
        header('Location: index.php');
        exit;
    }
    $err = 'Wrong password.';
}

if (admin_logged_in()) { header('Location: index.php'); exit; }
$PAGE_TITLE = 'CSS Arena — organiser';
$BASE = '..';
include __DIR__ . '/../partials/head.php';
?>
<section class="screen on">
  <div class="reg" style="padding-top:12vh">
    <div class="logo" data-logo="96"></div>
    <h1 style="margin-top:24px">Organiser</h1>
    <p class="sub">This console runs the competition.<br>It is reachable from this PC only.</p>
    <form method="post" autocomplete="off">
      <div class="field">
        <label for="pw">Password</label>
        <input id="pw" name="password" type="password" autofocus>
      </div>
      <div class="reghint"><?= htmlspecialchars($err) ?></div>
      <button class="btn" type="submit">Unlock</button>
    </form>
  </div>
</section>
<script src="<?= asset('assets/js/brand.js', '..') ?>"></script>
</body>
</html>
