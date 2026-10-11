<?php
/* ────────────────────────────────────────────────────────────────
   The lock on the organiser's door. Three separate bolts:

     1. admin/.htaccess           — Apache refuses anything but this PC
     2. the IP check below        — the same rule again, in case .htaccess
                                    is ignored (AllowOverride turned off)
     3. a password                — in case someone sits at the host PC

   Any page under admin/ starts by calling require_admin().
   ──────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/../lib/state.php';

function client_ip() {
    /* No proxies on a college LAN, so the socket address is the truth.
       Deliberately ignores X-Forwarded-For, which anyone could forge. */
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function ip_allowed() {
    global $ADMIN_IPS;
    $ip = client_ip();
    if (in_array($ip, (array) $ADMIN_IPS, true)) return true;
    /* ::ffff:127.0.0.1 — IPv4 seen through an IPv6 socket */
    if (strpos($ip, '::ffff:') === 0 && in_array(substr($ip, 7), (array) $ADMIN_IPS, true)) return true;
    return false;
}

function admin_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('arena_admin');
        session_start();
    }
}

function admin_logged_in() {
    admin_session_start();
    return !empty($_SESSION['admin_ok']);
}

/* Called at the top of every admin page. Never returns if the caller
   has no business being here. */
function require_admin($json = false) {
    if (!ip_allowed()) {
        http_response_code(403);
        if ($json) { header('Content-Type: application/json'); echo '{"ok":false,"error":"forbidden"}'; exit; }
        echo '<!doctype html><meta charset="utf-8"><title>403</title>'
           . '<body style="background:#0b0d13;color:#8892a6;font:14px system-ui;padding:60px;text-align:center">'
           . '<p style="font-size:15px;color:#e7ebf3">This page is only available on the organiser\'s PC.</p>'
           . '<p style="font-size:12px">Your address: ' . htmlspecialchars(client_ip()) . '</p>';
        exit;
    }
    if (!admin_logged_in()) {
        if ($json) { http_response_code(401); header('Content-Type: application/json'); echo '{"ok":false,"error":"login"}'; exit; }
        header('Location: login.php');
        exit;
    }
}

/* One CSRF token per admin session, checked on every action. */
function admin_token() {
    admin_session_start();
    if (empty($_SESSION['admin_token'])) $_SESSION['admin_token'] = bin2hex(random_bytes(16));
    return $_SESSION['admin_token'];
}

function check_token($sent) {
    admin_session_start();
    return !empty($_SESSION['admin_token']) && hash_equals($_SESSION['admin_token'], (string) $sent);
}
