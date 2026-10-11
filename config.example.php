<?php
/* ────────────────────────────────────────────────────────────────
   CSS Arena — configuration

   Copy this file to config.php and edit it once on the host PC.
   config.php is git-ignored: it holds the admin hash and the render
   secret, and neither belongs in a repository.
   ──────────────────────────────────────────────────────────────── */

/* ---- database (MySQL runs on this same PC, never on the network) ---- */
define('DB_HOST', 'localhost');
define('DB_NAME', 'vivacity');
define('DB_USER', 'root');
define('DB_PASS', '');

/* ---- admin login ----
   Generate a hash by running this once in a terminal:
     php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"
   Paste the result below. Never store the plain password here. */
define('ADMIN_PASSWORD_HASH', 'PASTE_YOUR_HASH_HERE');

/* ---- which machines may reach the admin portal and the results screen ----
   127.0.0.1 is this PC. Add the projector PC's IP here if it is a different
   machine. Everything not on this list gets a flat 403. */
$ADMIN_IPS = ['127.0.0.1', '::1'];

/* ---- headless browser used for scoring ----
   Full Chrome starts, loads the page and exits without ever writing an image
   on some builds, so this uses chrome-headless-shell: Google's standalone
   build made for exactly this job. It needs no internet and no graphics
   driver. Leave blank to auto-detect C:\xampp\chrome-headless-shell. */
define('CHROME_PATH', '');

/* ---- the competition ---- */
define('CANVAS_W', 400);
define('CANVAS_H', 300);
define('ACCURACY_FLOOR', 60);     // % needed to qualify in rounds 1 and 2
define('TIE_BAND', 0.1);          // accuracy is rounded to this before ranking
define('SUBMIT_GRACE_MS', 2000);  // late submissions allowed by this much
define('SCORE_WORKERS', 3);       // parallel Chrome processes in the scorer

/* ---- paths ---- */
define('BASE_URL', 'http://127.0.0.1/vivacity');
define('ROOT',     __DIR__);
define('STORAGE',  __DIR__ . '/storage');

/* ---- secret used to sign internal render URLs ----
   Generate your own:  php -r "echo bin2hex(random_bytes(24));"  */
define('RENDER_SECRET', 'GENERATE_YOUR_OWN');
