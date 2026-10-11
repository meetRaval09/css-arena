<?php
/* ────────────────────────────────────────────────────────────────
   Turn a piece of HTML and CSS into a round's target.

   The code is rendered through the very same shell that will render every
   submission, then the PNG — and only the PNG — is published to the
   participants. The source is written into storage/, which Apache refuses to
   serve, so View Source on a child PC shows nothing.

   CHANGED (design pass): after a successful render the result card now ends
   in a real confirm step — "Approve · back to console" — plus a way to
   render another or re-open the code. Nothing about rendering, scoring or
   the database changed; only the block marked NEW is different.
   ──────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/../lib/shoot.php';
require_once __DIR__ . '/../lib/diff.php';
require_once __DIR__ . '/../lib/palette.php';
require_once __DIR__ . '/../lib/hints.php';
require_once __DIR__ . '/../lib/sanitize.php';

@set_time_limit(120);
ensure_storage();

$code   = (string) ($_POST['code'] ?? '');
$name   = trim((string) ($_POST['name'] ?? ''));
$round  = (int) ($_POST['round'] ?? 1);
$err    = '';
$done   = null;
$design = null;

$act  = (string) ($_POST['do'] ?? 'render');
$note = '';

/* ---- attach an existing design to a round, or remove one ----
   Rendering has never deleted anything: every render inserts a new row and
   points one round at it. What was missing was a way to SEE the earlier ones,
   so a design that scrolled off the screen felt lost. The library below is
   that view, and these two actions are what it needs. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_token($_POST['token'] ?? '')
    && ($act === 'use' || $act === 'drop')) {

    $did = (int) ($_POST['design_id'] ?? 0);
    $q   = db()->prepare('SELECT * FROM designs WHERE id = ?');
    $q->execute([$did]);
    $d = $q->fetch();

    if (!$d) {
        $err = 'That design is no longer there.';
    } elseif ($act === 'use') {
        $rn = (int) ($_POST['round'] ?? 0);
        if ($rn < 1 || $rn > 3) {
            $err = 'Round must be 1, 2 or 3.';
        } else {
            db()->prepare('UPDATE rounds SET design_id = ? WHERE round_no = ?')->execute([$did, $rn]);
            $note = htmlspecialchars($d['name']) . ' is now the target for Round ' . $rn . '.';
        }
    } else {
        /* Only ever remove something no round is using. Deleting the target of
           a round that has already been scored would leave its results
           unexplainable. */
        $inUse = db()->prepare('SELECT COUNT(*) FROM rounds WHERE design_id = ?');
        $inUse->execute([$did]);
        if ((int) $inUse->fetchColumn() > 0) {
            $err = 'That design is attached to a round. Attach a different one first.';
        } else {
            @unlink(ROOT . '/' . $d['png_path']);
            @unlink(ROOT . '/' . $d['src_path']);
            db()->prepare('DELETE FROM designs WHERE id = ?')->execute([$did]);
            $note = 'Removed ' . htmlspecialchars($d['name']) . '.';
        }
    }
    $code = '';           // do not re-fill the editor from a library action
    $name = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_token($_POST['token'] ?? '') && $act === 'render') {
    if ($name === '') $name = 'Round ' . $round . ' design';
    $problem = code_problem($code);

    if (trim($code) === '')      $err = 'Paste the design code first.';
    elseif ($problem)            $err = $problem;
    elseif ($round < 1 || $round > 3) $err = 'Round must be 1, 2 or 3.';
    else {
        if (!is_dir(ROOT . '/assets/rounds')) @mkdir(ROOT . '/assets/rounds', 0777, true);
        if (!is_dir(STORAGE . '/designs'))    @mkdir(STORAGE . '/designs', 0777, true);

        $key = 'r' . $round . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $png = ROOT . '/assets/rounds/' . $key . '.png';
        $src = STORAGE . '/designs/' . $key . '.html';

        list($ok, $msg) = shoot($code, $png);
        if (!$ok) {
            $err = 'Could not render that: ' . $msg;
        } else {
            /* The zero point: what an empty submission scores against this
               design. Every accuracy is rescaled from it, so doing nothing
               lands on 0% instead of a third of a mark. */
            $blankPng = STORAGE . '/shots/_blank-' . $key . '.png';
            list($okB) = shoot('', $blankPng);
            $blankRaw  = $okB ? compare_png($png, $blankPng)['raw'] : 0;
            @unlink($blankPng);

            $palette = palette_from_png($png);
            $hints   = $round > 1 ? hints_from_code($code) : [];
            $par     = countable_length($code);

            file_put_contents($src, $code);

            db()->prepare('INSERT INTO designs
                             (name, for_round, src_path, png_path, palette_json,
                              hints_json, par_chars, blank_raw, canvas_w, canvas_h)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$name, $round, 'storage/designs/' . $key . '.html',
                           'assets/rounds/' . $key . '.png',
                           json_encode($palette), json_encode($hints),
                           $par, $blankRaw, CANVAS_W, CANVAS_H]);

            $id = (int) db()->lastInsertId();
            db()->prepare('UPDATE rounds SET design_id = ? WHERE round_no = ?')->execute([$id, $round]);

            $done = [
                'png'     => 'assets/rounds/' . $key . '.png',
                'palette' => $palette,
                'hints'   => $hints,
                'par'     => $par,
                'blank'   => $blankRaw,
                'round'   => $round,
                'name'    => $name,
            ];
        }
    }
}

/* what each round currently points at */
$attached = [];
foreach (db()->query('SELECT r.round_no, d.name, d.png_path, d.par_chars, d.blank_raw
                        FROM rounds r LEFT JOIN designs d ON d.id = r.design_id
                       ORDER BY r.round_no') as $row) {
    $attached[(int) $row['round_no']] = $row;
}

/* every design ever rendered, newest first, with the rounds using it */
$library = db()->query(
    'SELECT d.*, (SELECT GROUP_CONCAT(r.round_no) FROM rounds r WHERE r.design_id = d.id) AS used_by
       FROM designs d ORDER BY d.id DESC')->fetchAll();

$PAGE_TITLE = 'CSS Arena — designs';
$BASE = '..';
include __DIR__ . '/../partials/head.php';
?>
<section class="screen on">
  <div class="wrap">
    <div class="brand" style="margin-bottom:26px">
      <div class="logo" data-logo="42">A</div>
      <div><h1>Round designs</h1>
        <p>Only the picture reaches the participants ·
           <a href="index.php" style="color:var(--dim);text-decoration:none;border-bottom:1px solid var(--line)">back to console</a></p></div>
    </div>

    <div class="sect">Currently attached</div>
    <div class="card" style="padding:6px 18px;margin-bottom:8px">
      <?php foreach ([1, 2, 3] as $n): $a = $attached[$n] ?? null; ?>
        <div class="kv">
          <span>Round <?= $n ?></span>
          <b><?= $a && $a['name']
                ? htmlspecialchars($a['name']) . ' · par ' . (int) $a['par_chars'] . ' chars'
                : '<span style="color:var(--warn)">nothing attached</span>' ?></b>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($err): ?>
      <div class="card" style="padding:14px 18px;border-color:#5c2247;color:#ff9dc4;margin-bottom:8px">
        <?= htmlspecialchars($err) ?>
      </div>
    <?php endif; ?>

    <?php if ($note): ?>
      <div class="card" style="padding:14px 18px;border-left:3px solid var(--lime);margin-bottom:8px">
        <?= $note ?>
      </div>
    <?php endif; ?>

    <?php if ($done): ?>
      <!-- ═══ NEW: the result card now ends in a confirm step ═══ -->
      <div class="card" id="approve" style="padding:18px;border-color:var(--lime);
           box-shadow:0 0 0 3px rgba(201,242,74,.16);margin-bottom:8px">

        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
          <span class="pill q">attached</span>
          <b style="font:400 19px/1 var(--disp);letter-spacing:-.01em">
            Round <?= (int) $done['round'] ?> — <?= htmlspecialchars($done['name']) ?>
          </b>
        </div>

        <div style="display:flex;gap:22px;margin-top:16px;align-items:flex-start;flex-wrap:wrap">
          <img src="../<?= htmlspecialchars($done['png']) ?>?<?= time() ?>" width="400" height="300"
               alt="the rendered target for round <?= (int) $done['round'] ?>"
               style="border:3px solid var(--acc2);border-radius:8px;
                      box-shadow:6px 6px 0 rgba(124,92,255,.42);display:block">
          <div style="font:12px/1.9 var(--mono);color:var(--mut);min-width:260px">
            <div>palette &nbsp;<?= htmlspecialchars(implode('  ', $done['palette'])) ?></div>
            <div>par &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<?= (int) $done['par'] ?> characters</div>
            <div>blank &nbsp;&nbsp;&nbsp;<?= number_format($done['blank'], 2) ?>% → rescaled to 0%</div>
            <div>hint &nbsp;&nbsp;&nbsp;&nbsp;<?= $done['hints']
                  ? htmlspecialchars(implode(', ', $done['hints']))
                  : ($done['round'] > 1 ? 'nothing uncommon found' : 'none — round 1 gets no hint') ?></div>
          </div>
        </div>

        <p class="dim" style="font-size:12px;margin:16px 0 0;line-height:1.7">
          Check the picture above is the target you meant. It is already live on
          Round <?= (int) $done['round'] ?> — approving just takes you back to the console.
          Render as many as you like: every one is kept in the library below, and
          you can put an earlier one back on a round at any time.
        </p>

        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;
                    margin-top:18px;padding-top:16px;border-top:2px solid var(--line)">
          <a class="btn" href="index.php" style="text-decoration:none;display:inline-block">
            APPROVE · BACK TO CONSOLE
          </a>
          <a class="btn ghost sm" href="#newdesign" style="text-decoration:none;display:inline-block">
            Render another
          </a>
          <span style="flex:1"></span>
          <span class="dim" style="font-size:11.5px">
            Round <?= (int) $done['round'] ?> is ready to start from the console.
          </span>
        </div>
      </div>
      <!-- ═══ end NEW ═══ -->
    <?php endif; ?>

    <div class="sect">Design library <span class="dim" style="font-weight:400">· <?= count($library) ?> rendered</span></div>
    <?php if (!$library): ?>
      <div class="card" style="padding:16px 18px;margin-bottom:8px">
        <span class="dim">Nothing rendered yet. Paste a design below and it will appear here.</span>
      </div>
    <?php else: ?>
      <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:8px">
        <?php foreach ($library as $d):
              $used = array_filter(explode(',', (string) $d['used_by'])); ?>
          <div class="card" style="padding:12px;width:236px">
            <img src="../<?= htmlspecialchars($d['png_path']) ?>" width="212" height="159"
                 alt="<?= htmlspecialchars($d['name']) ?>" loading="lazy"
                 style="display:block;border-radius:6px;border:2px solid var(--line);background:#fff">

            <div style="margin-top:10px;font-size:13px;font-weight:600;line-height:1.3">
              <?= htmlspecialchars($d['name']) ?>
            </div>
            <div class="dim" style="font:11px/1.6 var(--mono);margin-top:3px">
              made for R<?= (int) $d['for_round'] ?> · par <?= (int) $d['par_chars'] ?>
            </div>

            <div style="margin-top:8px;min-height:20px">
              <?php if ($used): ?>
                <?php foreach ($used as $u): ?>
                  <span class="pill q" style="margin-right:4px">live on R<?= (int) $u ?></span>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="dim" style="font-size:11px">not in use</span>
              <?php endif; ?>
            </div>

            <div style="display:flex;gap:5px;align-items:center;margin-top:10px;
                        padding-top:9px;border-top:1px solid var(--line)">
              <span class="dim" style="font-size:11px">use for</span>
              <?php foreach ([1, 2, 3] as $n): ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="token" value="<?= htmlspecialchars(admin_token()) ?>">
                  <input type="hidden" name="do" value="use">
                  <input type="hidden" name="design_id" value="<?= (int) $d['id'] ?>">
                  <input type="hidden" name="round" value="<?= $n ?>">
                  <button class="btn sm ghost" type="submit"
                          style="padding:3px 9px;min-width:0">R<?= $n ?></button>
                </form>
              <?php endforeach; ?>
              <span style="flex:1"></span>
              <?php if (!$used): ?>
                <form method="post" style="display:inline"
                      onsubmit="return confirm('Remove this design for good?')">
                  <input type="hidden" name="token" value="<?= htmlspecialchars(admin_token()) ?>">
                  <input type="hidden" name="do" value="drop">
                  <input type="hidden" name="design_id" value="<?= (int) $d['id'] ?>">
                  <button class="btn sm ghost" type="submit" title="remove"
                          style="padding:3px 8px;min-width:0;color:var(--bad)">&times;</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="dim" style="font-size:11.5px;margin:0 0 18px;line-height:1.7">
        Nothing here is ever thrown away by rendering something new. A design in use
        cannot be removed — attach a different one to that round first.
      </p>
    <?php endif; ?>

    <div class="sect" id="newdesign">New design</div>
    <form method="post" class="card" style="padding:18px">
      <input type="hidden" name="token" value="<?= htmlspecialchars(admin_token()) ?>">
      <textarea name="code" spellcheck="false" wrap="off"
        style="width:100%;height:260px;background:var(--ed-bg);color:var(--tx);border:2px solid var(--ed-edge);
               border-radius:14px;padding:14px;font:13px/1.6 var(--mono);resize:vertical;
               caret-color:var(--lime);outline:none"
        placeholder="&lt;style&gt;&#10;  body { margin:0; height:300px; background:#0F5257 }&#10;&lt;/style&gt;"><?= htmlspecialchars($code) ?></textarea>
      <div style="display:flex;gap:14px;align-items:center;margin-top:14px;flex-wrap:wrap">
        <label class="st-f">Name <input type="text" name="name" value="<?= htmlspecialchars($name) ?>" placeholder="Pinwheel"></label>
        <label class="st-f">Round
          <select name="round">
            <option value="1"<?= $round === 1 ? ' selected' : '' ?>>1 · Easy</option>
            <option value="2"<?= $round === 2 ? ' selected' : '' ?>>2 · Intermediate</option>
            <option value="3"<?= $round === 3 ? ' selected' : '' ?>>3 · Final</option>
          </select>
        </label>
        <span style="flex:1"></span>
        <button class="btn" type="submit" id="renderGo">RENDER AND ATTACH</button>
      </div>
      <p class="dim" style="font-size:11.5px;margin:14px 0 0;line-height:1.7">
        Rendered at <?= CANVAS_W ?> × <?= CANVAS_H ?> through the exact shell used for scoring.
        <b>body</b> fills the whole canvas with no margin, so a background, border or
        box-shadow on body covers the entire picture.
        Rounds 2 and 3 also get a hint comment listing the uncommon properties your design uses; round 1 gets none.
      </p>
    </form>
  </div>
</section>
<script src="<?= asset('assets/js/brand.js', '..') ?>"></script>
<?php if ($done): ?>
<script>
/* Land the organiser on the result, not the top of the page. */
(function () {
  var box = document.getElementById('approve');
  if (box) window.scrollTo(0, box.offsetTop - 24);
})();
</script>
<?php endif; ?>
</body>
</html>
