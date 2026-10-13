/* ────────────────────────────────────────────────────────────────
   Everything around the editor: the clock, autosave, the accuracy check
   and submitting.

   The clock is the server's, not this PC's. A lab machine with the wrong
   Windows time still stops at the right moment.
   ──────────────────────────────────────────────────────────────── */
(function () {
  var clockOffset = SERVER0 - Date.now();
  var endMs       = END_MS;
  var finished    = false;

  function serverNow() { return Date.now() + clockOffset; }
  function $(id) { return document.getElementById(id); }

  /* ---------- the round clock ---------- */
  function mmss(sec) {
    if (sec < 0) sec = 0;
    var m = Math.floor(sec / 60), s = Math.floor(sec % 60);
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  function tick() {
    var left = Math.round((endMs - serverNow()) / 1000);
    $('tval').textContent = mmss(left);
    var t = $('timer');
    t.classList.toggle('warn', left <= 120 && left > 30);
    t.classList.toggle('bad',  left <= 30);
    /* the hairline under the top bar drains with the clock */
    var bar = $('tprog');
    if (bar && typeof TOTAL_MS === 'number' && TOTAL_MS > 0) {
      var f = Math.max(0, Math.min(1, (endMs - serverNow()) / TOTAL_MS));
      bar.style.transform = 'scaleX(' + f.toFixed(4) + ')';
      bar.className = left <= 30 ? 'bad' : left <= 120 ? 'warn' : '';
    }
    if (left <= 0 && !finished) submit(true);
  }
  setInterval(tick, 250);
  tick();

  /* ---------- autosave ----------
     Every few seconds, and only when something actually changed. A PC that
     dies loses at most this interval, and the draft comes back on reconnect. */
  var lastSaved = null, saving = false, dirty = false;

  codeEl.addEventListener('input', function () { dirty = true; });

  function save(done) {
    if (saving || finished) return;
    var code = codeEl.value;
    if (code === lastSaved) { if (done) done(); return; }
    saving = true;

    var body = new FormData();
    body.append('code', code);
    fetch('api/autosave.php', { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        saving = false;
        if (d && d.ok) {
          lastSaved = code;
          dirty = false;
          note('Saved · ' + d.chars + ' characters counted', false);
          /* the server is the authority on the count */
          $('chars').textContent = d.chars;
          if (d.ends_ms) endMs = d.ends_ms;      // organiser granted extra time
        } else if (d && d.error) {
          note(d.error, true);
        }
        if (done) done();
      })
      .catch(function () {
        saving = false;
        note('Cannot reach the server — keep working, it will retry', true);
        if (done) done();
      });
  }

  var noteTimer = null;
  function note(msg, bad) {
    var el = $('saveNote');
    el.textContent = msg;
    el.style.color = bad ? 'var(--bad)' : 'var(--dim)';
    clearTimeout(noteTimer);
    noteTimer = setTimeout(function () {
      el.style.color = '';
      el.textContent = "Comments are free — they don't count toward your character total.";
    }, 4000);
  }

  setInterval(function () { if (dirty) save(); }, 5000);

  /* ---------- stay in touch with the server ----------
     Three jobs: it tells the admin console this PC is alive, it picks up extra
     time the organiser grants mid-round, and it notices when a round is ended
     early so the arena does not keep running after the bell. */
  setInterval(function () {
    if (finished) return;
    fetch('api/state.php', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) return;
        clockOffset = d.now - Date.now();
        if (!d.round || d.round.status !== 'running') { submit(true); return; }
        if (d.round.ends_ms && d.round.ends_ms !== endMs) {
          var more = d.round.ends_ms > endMs;
          endMs = d.round.ends_ms;
          if (more) note('The organiser gave you extra time', false);
        }
      })
      .catch(function () {});
  }, 3000);

  /* ---------- check accuracy ----------
     Rendered by the same headless browser that will score the final entry, so
     the number here is the number that counts. Limited to one check every
     15 seconds per person — 60 PCs asking at once would flatten the host. */
  var COOLDOWN_MS = 15000;
  var lastCheck = 0, checking = false;

  $('checkBtn').addEventListener('click', function () {
    if (checking || !HAS_DESIGN) {
      if (!HAS_DESIGN) $('accMsg').textContent = 'No design has been attached to this round yet.';
      return;
    }
    var wait = COOLDOWN_MS - (Date.now() - lastCheck);
    if (wait > 0) {
      $('accMsg').textContent = 'Hold on ' + Math.ceil(wait / 1000) + 's — one check every 15 seconds.';
      return;
    }
    runCheck();
  });

  /* The number rolls from where it was to where it is, so a change reads as a
     change. 650ms, eased out; skipped entirely for reduced motion. */
  var REDUCED = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  function countTo(el, to, fmt) {
    var from = parseFloat((el.textContent || '').replace(/[^0-9.\-]/g, ''));
    if (isNaN(from)) from = 0;
    if (REDUCED) { el.textContent = fmt(to); return; }
    var t0 = performance.now(), D = 650;
    (function step(now) {
      var k = Math.min(1, (now - t0) / D), e = 1 - Math.pow(1 - k, 3);
      el.textContent = fmt(from + (to - from) * e);
      if (k < 1) requestAnimationFrame(step);
    })(t0);
  }
  /* a thin line drains under the button for the 15s cooldown */
  function coolDown(btn) {
    btn.classList.remove('cool'); void btn.offsetWidth;
    btn.style.setProperty('--cd', COOLDOWN_MS + 'ms');
    btn.classList.add('cool');
  }

  function runCheck() {
    checking = true;
    lastCheck = Date.now();
    var btn = $('checkBtn');
    btn.disabled = true;
    $('accMsg').textContent = 'Rendering your code the same way it will be scored…';

    save(function () {
      fetch('api/check.php', { method: 'POST' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          checking = false; btn.disabled = false;
          if (!d.ok) { $('accMsg').textContent = d.error || 'Could not render that.'; return; }
          countTo($('accVal'),   d.accuracy, function (v) { return v.toFixed(2) + '%'; });
          countTo($('accExact'), d.exact,    function (v) { return v.toFixed(1) + '%'; });
          countTo($('accOff'),   d.off,      function (v) { return Math.round(v).toLocaleString(); });
          coolDown(btn);
          var v = $('accVal');
          v.classList.remove('ok', 'mid', 'low');
          v.classList.add(d.accuracy >= 95 ? 'ok' : d.accuracy >= 80 ? 'mid' : 'low');
          var fill = $('accFill');
          if (fill) { fill.style.width = Math.max(0, Math.min(100, d.accuracy)) + '%';
                      fill.className = v.className.replace('val', '').trim(); }
          $('accMsg').textContent   = d.accuracy >= 99.9
            ? 'Pixel perfect. Nothing left to fix.'
            : 'Slide "overlay target" above your output to see what is off.';
        })
        .catch(function () {
          checking = false; btn.disabled = false;
          $('accMsg').textContent = 'The server did not answer. Try again in a moment.';
        });
    });
  }

  /* ---------- submit ---------- */
  function submit(auto) {
    if (finished) return;
    if (!auto && !confirm('Submit your design?\n\nYou cannot come back to it.')) return;
    finished = true;

    var body = new FormData();
    body.append('code', codeEl.value);
    body.append('auto', auto ? '1' : '0');
    fetch('api/submit.php', { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function () { window.location.href = 'thanks.php'; })
      .catch(function () { window.location.href = 'thanks.php'; });
  }

  $('submitBtn').addEventListener('click', function () { submit(false); });

  /* a wandering mouse should not cost somebody their round */
  window.addEventListener('beforeunload', function (e) {
    if (finished) return;
    save();
    e.preventDefault();
    e.returnValue = '';
  });

  /* ---------- start ---------- */
  codeEl.value = START;
  render();
  lastSaved = START;
  codeEl.focus();
})();
