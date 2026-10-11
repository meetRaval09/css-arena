<?php
require_once __DIR__ . '/config.php';

if (!function_exists('db')) {
function db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
}

/* Cache-busting for our own CSS and JavaScript.

   Without this a child PC that visited during the rehearsal keeps serving the
   old file from cache, and the page quietly misbehaves — a form that stops
   sending a field it never knew about, for instance. Stamping each file with
   its own last-modified time means the moment we change a file, every browser
   fetches it again, and while it is unchanged they keep the cached copy. */
function asset($rel, $base = '.') {
    $f = ROOT . '/' . ltrim($rel, '/');
    return rtrim($base, '/') . '/' . ltrim($rel, '/')
         . '?v=' . (is_file($f) ? filemtime($f) : time());
}

/* server time in milliseconds — every clock in the system comes from here */
function now_ms() {
    return (int) round(microtime(true) * 1000);
}

/* Anything that goes wrong inside an API endpoint should come back as JSON
   saying what happened. Without this, a PHP error returns an HTML error page,
   the browser cannot parse it, and the screen says only "no answer from the
   server" — which tells whoever is running the event nothing at all. */
function json_errors() {
    set_exception_handler(function ($e) {
        http_response_code(500);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        $msg = $e->getMessage();
        /* the one failure worth naming outright, because it has a fix */
        if (stripos($msg, 'email') !== false && stripos($msg, 'column') !== false) {
            $msg = 'The database is missing the email column — run '
                 . 'sql/upgrade-email-phone.sql in phpMyAdmin.';
        }
        echo json_encode(['ok' => false, 'error' => $msg]);
    });
}

function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}
