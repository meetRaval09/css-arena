<?php
/* ────────────────────────────────────────────────────────────────
   The arena. Reached only while a round is running and only by someone
   taking part in it — anyone else is sent back to the lobby.

   The design arrives as a PNG. The HTML and CSS that produced it never
   leaves the host PC, so View Source shows a participant nothing useful.
   ──────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/lib/state.php';
require_once __DIR__ . '/lib/sanitize.php';

header('Cache-Control: no-store, must-revalidate');

$p = me();
if (!$p) { header('Location: index.php'); exit; }

$r = current_round();
if (!$r || $r['status'] !== 'running' || !is_eligible($p['id'], $r['round_no'], $r['id'])) {
    header('Location: index.php');
    exit;
}

$sub = submission_for($p['id'], $r['id']);
if ($sub && (int) $sub['is_final'] === 1) { header('Location: thanks.php'); exit; }

/* the design, if the organiser has attached one to this round */
$design = null;
if ($r['design_id']) {
    $q = db()->prepare('SELECT * FROM designs WHERE id = ?');
    $q->execute([$r['design_id']]);
    $design = $q->fetch() ?: null;
}

$palette = $design ? (json_decode($design['palette_json'], true) ?: []) : [];
$hints   = ($design && (int) $r['round_no'] > 1) ? (json_decode($design['hints_json'], true) ?: []) : [];

/* Where the participant starts. A draft from a crashed PC wins over the
   boilerplate, which is the whole point of autosaving. */
require_once __DIR__ . '/lib/hints.php';

$boiler = "<div></div>\n<style>\n  div {\n    width: 100px;\n    height: 100px;\n"
        . "    background: #dd6b4d;\n  }\n</style>\n\n"
        . "<!-- OBJECTIVE -->\n"
        . "<!-- Recreate the target on the right using only HTML and CSS.\n"
        . "     In the final round, fewer characters scores higher. -->\n\n"
        . "<!-- Comments are free — they are not counted. -->";

if ($hints) $boiler = hint_block($hints) . "\n\n" . $boiler;

$start = ($sub && $sub['code'] !== '') ? $sub['code'] : $boiler;

$startMs = ms_of($r['started_at']);
$endMs   = deadline_ms($startMs, (int) $r['time_limit_s'], $sub ? (int) $sub['extra_time_ms'] : 0);

$LEVEL = ['easy' => 'Easy', 'medium' => 'Intermediate', 'hard' => 'Hard'];

$PAGE_TITLE = 'Round ' . $r['round_no'] . ' — CSS Arena';
include __DIR__ . '/partials/head.php';
?>
<section class="screen on" id="s-arena">
 <div class="arena">
  <div class="topbar">
    <div class="tbmark" data-logo="38"></div>
    <div class="rnd">
      <div class="no" data-logo-hide><?= (int) $r['round_no'] ?></div>
      <div>
        <?php /* The ROUND, never the design's name. A design attached to Round 2
                 may still be called "Round 1 design" from when it was made, and
                 a good name is worse than a stale one: "Bullseye" tells a
                 participant it is circles before they have looked. */ ?>
        <div class="nm"><?= $design ? 'Round ' . (int) $r['round_no'] . ' design' : 'No design set' ?></div>
        <div class="df">Round <?= (int) $r['round_no'] ?> ·
          <?= htmlspecialchars($LEVEL[$r['difficulty']] ?? $r['difficulty']) ?></div>
      </div>
    </div>

    <div class="timer" id="timer">
      <div class="t" id="tval">—:—</div>
      <div class="lb">REMAINING</div>
    </div>

    <div class="meta">
      <div class="stat"><div class="v" id="chars">0</div><div class="k">CHARACTERS</div></div>
      <button class="btn" id="submitBtn">Submit</button>
    </div>
  </div>

  <div class="panes">
    <!-- editor -->
    <div class="pane">
      <div class="ph">Your code
        <span class="r">
          <label><input type="checkbox" id="acToggle" checked>autocomplete</label>
          <span class="badge">HTML + CSS</span>
        </span>
      </div>
      <div class="pb">
        <div class="editor">
          <div class="gutter" id="gutter"><div class="gi" id="gnums"></div></div>
          <div class="ed">
            <div class="activeline" id="aline"></div>
            <pre class="hl" id="hl"></pre>
            <textarea id="code" spellcheck="false" wrap="off" autocomplete="off"
                      autocorrect="off" autocapitalize="off"></textarea>
            <div class="ac" id="ac"></div>
          </div>
        </div>
        <div class="lint" id="lint"></div>
        <div class="edfoot">
          <button class="btn-check" id="checkBtn">Check accuracy</button>
          <span class="note" id="saveNote">Comments are free — they don't count toward your character total.</span>
        </div>
      </div>
    </div>

    <!-- output -->
    <div class="pane">
      <div class="ph">Your output
        <span class="r">
          <span class="slider">
            <label for="ov">overlay target</label>
            <input type="range" id="ov" min="0" max="100" value="0">
          </span>
        </span>
      </div>
      <div class="pb">
        <div class="stage">
          <div class="canvas">
            <iframe id="out" sandbox="allow-same-origin" title="output"></iframe>
            <img id="overlay" width="400" height="300" alt=""
                 src="<?= $design ? htmlspecialchars($design['png_path']) : '' ?>">
          </div>
        </div>
        <div class="accbar">
          <div class="accrow">
            <div>
              <div class="val" id="accVal">—</div>
              <div class="lbl">ACCURACY</div>
            </div>
            <div class="side">
              <div><b id="accExact">—</b><span>EXACT PIXELS</span></div>
              <div><b id="accOff">—</b><span>PIXELS OFF</span></div>
            </div>
          </div>
          <div class="msg" id="accMsg">Press <b style="color:var(--mut)">Check accuracy</b> to compare your render with the target.</div>
        </div>
      </div>
    </div>

    <!-- target -->
    <div class="pane">
      <div class="ph">Target
        <span class="r"><span class="badge"><?= CANVAS_W ?> × <?= CANVAS_H ?></span></span>
      </div>
      <div class="pb">
        <div class="stage">
          <div class="col">
            <div class="canvas">
              <img id="ref" width="400" height="300" alt="the design you have to replicate"
                   src="<?= $design ? htmlspecialchars($design['png_path']) : '' ?>">
            </div>
            <div class="palette">
              <div class="pal-head">
                <span class="pal-title">Colors</span>
                <span class="kbd">CTRL</span><span class="kbd">SHIFT</span><span class="kbd">C</span>
                <span class="pal-rule"></span>
              </div>
              <div class="pal-list" id="palList"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
 </div>
</section>

<script>
var ROUND    = <?= (int) $r['round_no'] ?>;
var END_MS   = <?= (int) $endMs ?>;
var SERVER0  = <?= now_ms() ?>;
var PALETTE  = <?= json_encode($palette) ?>;
var START    = <?= json_encode($start) ?>;
var HAS_DESIGN = <?= $design ? 'true' : 'false' ?>;
</script>
<script src="<?= asset('assets/js/brand.js') ?>"></script>
<script src="<?= asset('assets/js/editor.js') ?>"></script>
<script src="<?= asset('assets/js/arena.js') ?>"></script>
</body>
</html>
