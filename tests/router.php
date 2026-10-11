<?php
require_once __DIR__ . '/db_sqlite.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . '/..' . $path;
if ($path === '/') $file = __DIR__ . '/../index.php';
if (is_file($file) && !preg_match('/\.php$/', $file)) return false;
if (is_file($file)) { chdir(dirname($file)); require $file; return true; }
http_response_code(404); echo 'not found';
