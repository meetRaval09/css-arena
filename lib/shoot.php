<?php
/* ────────────────────────────────────────────────────────────────
   Renders a piece of HTML/CSS to a 400x300 PNG with headless Chrome.
   Every reference design and every submission goes through this one
   function, which is what makes a perfect answer score exactly 100.
   ──────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/../config.php';

/* Make sure the folders we write into exist. Empty folders do not survive a
   zip, so this runs on every include and is cheap after the first time. */
function ensure_storage() {
    foreach ([STORAGE, STORAGE . '/render', STORAGE . '/shots', STORAGE . '/logs'] as $d) {
        if (!is_dir($d)) @mkdir($d, 0777, true);
    }
    $ht = STORAGE . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\n");
}
ensure_storage();

/* True when the binary is chrome-headless-shell (or Playwright's headless_shell),
   which IS the old headless implementation — it must not be given --headless. */
function is_headless_shell($exe) {
    $base = strtolower(basename((string) $exe));
    return strpos($base, 'headless-shell') !== false || strpos($base, 'headless_shell') !== false;
}

function chrome_path() {
    /* An override for automated tests, which run on a machine that has no
       Windows paths. Unset in production, where CHROME_PATH decides. */
    $env = getenv('ARENA_CHROME');
    if ($env && is_file($env)) return $env;

    if (CHROME_PATH !== '' && is_file(CHROME_PATH)) return CHROME_PATH;

    /* prefer chrome-headless-shell if it has been unpacked next to XAMPP —
       it is purpose-built for this and does not need a working graphics stack */
    foreach ([
        'C:\\xampp\\chrome-headless-shell\\chrome-headless-shell.exe',
        'C:\\xampp\\chrome-headless-shell\\chrome-headless-shell-win64\\chrome-headless-shell.exe',
        __DIR__ . '/../bin/chrome-headless-shell.exe',
    ] as $hs) {
        if (is_file($hs)) return $hs;
    }

    $local = getenv('LOCALAPPDATA');
    $candidates = [
        'C:\Program Files\Google\Chrome\Application\chrome.exe',
        'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
        $local ? $local . '\Google\Chrome\Application\chrome.exe' : null,
        /* Edge is Chromium and ships with Windows — a good fallback */
        'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
        'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    ];
    foreach ($candidates as $c) {
        if ($c && is_file($c)) return $c;
    }
    return null;
}

/* The shell every submission and every target is rendered in.

   `body` is made to fill the canvas exactly: 400x300 at the top-left corner,
   with no margin. Without this, body is only as tall as whatever is inside it
   — 100px if there is one small div, and ZERO if the code has no elements at
   all — so `background`, `box-shadow` and `border` on body paint a strip
   instead of the picture, or nothing whatever. Backgrounds hid the problem
   because a body background propagates to the canvas on its own; box-shadow
   does not propagate, which is how this came to light.

   This is a deliberate departure from CSS Battle, which leaves the browser's
   default 8px body margin in place as part of the puzzle. For a hall full of
   students meeting CSS for the first time, an invisible 8px offset is a
   cruelty, not a challenge.

   MUST STAY IDENTICAL to wrap() in assets/js/editor.js. That function draws
   the live preview the participant is looking at; if the two ever drift, the
   preview and the score disagree and nobody can tell who is right.

   The trailing rule freezes animation so the same code always produces the
   same picture. */
function render_shell($code) {
    return '<!doctype html><meta charset="utf-8">'
         . '<style>html{width:' . CANVAS_W . 'px;height:' . CANVAS_H . 'px;overflow:hidden}'
         . 'body{margin:0;width:' . CANVAS_W . 'px;height:' . CANVAS_H . 'px}</style>'
         . $code
         . '<style>*,*::before,*::after{animation-play-state:paused!important;'
         . 'animation-delay:0s!important;transition:none!important}</style>';
}

/**
 * Render $code and write a PNG to $outPng.
 * Returns [ok(bool), message(string), seconds(float)].
 */
function shoot($code, $outPng) {
    $t0 = microtime(true);

    if (!function_exists('exec')) {
        return [false, 'exec() is disabled in php.ini (see disable_functions)', 0];
    }
    $chrome = chrome_path();
    if (!$chrome) {
        return [false, 'No Chrome or Edge found. Set CHROME_PATH in config.php', 0];
    }

    $job     = bin2hex(random_bytes(6));
    $htmlIn  = STORAGE . '/render/' . $job . '.html';
    $profile = STORAGE . '/render/profile-' . $job;

    if (!is_dir(dirname($htmlIn))) @mkdir(dirname($htmlIn), 0777, true);
    if (!is_dir(dirname($outPng))) @mkdir(dirname($outPng), 0777, true);
    file_put_contents($htmlIn, render_shell($code));
    @mkdir($profile, 0777, true);
    @unlink($outPng);

    $url = 'file:///' . str_replace('\\', '/', $htmlIn);

    /* Arguments are passed as a list, never as one string. On Windows a single
       string would go through cmd.exe, which mangles a command whose program
       path is quoted and contains spaces ("C:\Program Files\..."). Passing a
       list skips the shell entirely, so paths with spaces just work. */
    $args = [$chrome];
    if (!is_headless_shell($chrome)) {
        /* the full browser needs telling to run without a window */
        $args[] = '--headless=new';
        $args[] = '--no-first-run';
        $args[] = '--no-default-browser-check';
        $args[] = '--disable-extensions';
    }
    $args = array_merge($args, [
        '--disable-gpu',
        '--no-sandbox',
        '--hide-scrollbars',
        '--force-device-scale-factor=1',
        '--window-size=' . CANVAS_W . ',' . CANVAS_H,
        '--run-all-compositor-stages-before-draw',
        '--virtual-time-budget=1500',
        '--user-data-dir=' . $profile,
        '--screenshot=' . $outPng,
        $url,
    ]);

    $out = []; $code_ = -1;
    $pipes = [];
    $proc = !function_exists('proc_open') ? false : @proc_open(
        $args,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null, null,
        ['bypass_shell' => true]
    );

    if (is_resource($proc)) {
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code_  = proc_close($proc);
        $out    = preg_split('/\r?\n/', trim($stdout . "\n" . $stderr));
    } else {
        /* Fallback for a PHP with proc_open disabled. PHP already wraps the
           command in quotes for cmd.exe, so do NOT add another pair here. */
        $one = '"' . $chrome . '"';
        foreach (array_slice($args, 1) as $a) {
            $one .= ' ' . (strpos($a, ' ') === false ? $a
                        : (strpos($a, '=') !== false
                           ? substr($a, 0, strpos($a, '=') + 1) . '"' . substr($a, strpos($a, '=') + 1) . '"'
                           : '"' . $a . '"'));
        }
        exec($one . ' 2>&1', $out, $code_);
    }

    @unlink($htmlIn);
    rrmdir($profile);

    /* Windows writes the file a moment after the browser exits — antivirus
       scanning it can add a second or more — and PHP caches file lookups.
       Wait for it properly instead of declaring failure too early. */
    $deadline = microtime(true) + 3.0;
    $wrote = false;
    do {
        clearstatcache(true, $outPng);
        if (is_file($outPng) && filesize($outPng) > 100) { $wrote = true; break; }
        usleep(100000);
    } while (microtime(true) < $deadline);

    $secs = microtime(true) - $t0;

    if (!$wrote) {
        $tail = trim(implode("\n", array_slice(array_filter($out), -4)));
        return [false, 'Chrome produced no image (exit ' . $code_ . '). ' . $tail, $secs];
    }
    return [true, 'ok', $secs];
}

function rrmdir($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . DIRECTORY_SEPARATOR . $f;
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}
