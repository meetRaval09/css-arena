/* ────────────────────────────────────────────────────────────────
   Registration and the waiting room.

   The only clock that matters is the server's. Every reply to api/state.php
   carries `now`, and we keep the difference between that and this PC's clock,
   so a lab machine with the wrong time still counts down correctly.
   ──────────────────────────────────────────────────────────────── */
(function () {
  var POLL_MS = 1000;
  var clockOffset = 0;                       // server_ms - Date.now()
  var registered = document.getElementById('s-wait').classList.contains('on');
  var startingAt = null;                     // ms when the round actually begins

  function serverNow() { return Date.now() + clockOffset; }
  function $(id) { return document.getElementById(id); }
  function show(id) {
    var s = document.querySelectorAll('.screen');
    for (var i = 0; i < s.length; i++) s[i].classList.toggle('on', s[i].id === id);
  }

  /* ---------- registration ---------- */
  var form = $('regForm');
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var name  = $('rn').value.trim();
      var email = $('re').value.trim();
      var phone = $('rp').value.trim();
      var hint  = $('regHint');

      /* caught here only to save a round trip — the server checks all of this
         again and is the one that decides */
      if (name.length < 2) { hint.textContent = 'Please type your name.'; $('rn').focus(); return; }
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        hint.textContent = 'Please type a valid email address.'; $('re').focus(); return;
      }
      var digits = phone.replace(/[^0-9]/g, '');
      if (digits.length === 0) {
        hint.textContent = 'Please type your phone number.'; $('rp').focus(); return;
      }
      if (digits.length < 7 || digits.length > 15) {
        hint.textContent = 'That phone number does not look right.'; $('rp').focus(); return;
      }
      /* The seat is required now: it is how the server tells a student coming
         back to their own PC apart from a different student who typed the
         same email by mistake. */
      if ($('rs').value.trim() === '') {
        hint.textContent = 'Please type your PC / seat number — it is on the machine.';
        $('rs').focus(); return;
      }

      var btn = $('regGo');
      btn.disabled = true; hint.textContent = '';

      var body = new FormData();
      body.append('name', name);
      body.append('email', email);
      body.append('phone', phone);
      body.append('seat', $('rs').value.trim());

      fetch('api/register.php', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          btn.disabled = false;
          if (!d.ok) { hint.textContent = d.error || 'Could not register.'; return; }
          $('waitName').textContent = d.name;
          registered = true;
          show('s-wait');
          poll();
        })
        .catch(function () {
          btn.disabled = false;
          hint.textContent = 'No answer from the server. Tell the organiser.';
        });
    });
    $('rn').focus();
  }

  /* ---------- the countdown overlay ---------- */
  function paintCountdown() {
    var cd = $('cdown');
    if (startingAt === null) { cd.classList.remove('on'); return; }
    var left = startingAt - serverNow();
    if (left <= 0) { window.location.href = 'play.php'; return; }
    cd.classList.add('on');
    var n = String(Math.ceil(left / 1000)), el = $('cdnum');
    if (el.textContent !== n) {
      /* each new second lands with a pop and one ring going out */
      el.textContent = n;
      el.classList.remove('tick-in'); void el.offsetWidth; el.classList.add('tick-in');
    }
  }
  setInterval(function () { if (startingAt !== null) paintCountdown(); }, 100);

  /* ---------- roster chips ---------- */
  function paintRoster(list) {
    var box = $('roster');
    if (!box || !list) return;
    var html = '';
    for (var i = 0; i < list.length; i++) {
      html += '<span class="chip' + (list[i].me ? ' me' : '') + '">' +
              escapeHtml(list[i].name) + '</span>';
    }
    box.innerHTML = html;
  }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function mmss(sec) {
    var m = Math.floor(sec / 60), s = sec % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  /* ---------- the poll ---------- */
  var failures = 0;

  function poll() {
    if (!registered) return;
    fetch('api/state.php', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        failures = 0;
        clockOffset = d.now - Date.now();

        if (d.go === 'index.php') { window.location.reload(); return; }

        if (d.me) {
          $('waitName').textContent = d.me.name;
          $('waitSeat').textContent = d.me.seat || '—';
        }
        if (typeof d.count === 'number') $('regCount').textContent = d.count;
        paintRoster(d.roster);

        var note = $('waitNote');
        var r = d.round;

        if (!r) {
          $('waitRound').textContent = 'the first round';
          $('waitLen').textContent = '—';
          note.textContent = '';
          startingAt = null; $('cdown').classList.remove('on');
        } else {
          $('waitRound').textContent = 'Round ' + r.no;
          $('waitLen').textContent = mmss(r.time_limit_s);
          $('cdlb').textContent = 'ROUND ' + r.no + ' STARTS';

          if (r.status === 'running' && !r.eligible) {
            note.textContent = 'Round ' + r.no + ' is under way. You are not in this round — '
                             + 'the standings will be read out by the organiser.';
            startingAt = null; $('cdown').classList.remove('on');
          } else if (d.go === 'thanks.php') {
            window.location.href = 'thanks.php';
          } else if (d.go === 'play.php') {
            note.textContent = '';
            if (r.started_ms && r.started_ms > d.now) {
              startingAt = r.started_ms;            // synchronised lead-in
              paintCountdown();
            } else {
              window.location.href = 'play.php';    // joined late, or reconnected
            }
          } else {
            note.textContent = r.status === 'ended'
              ? 'Round ' + r.no + ' has finished. Sit tight — the organiser is scoring.'
              : '';
            startingAt = null; $('cdown').classList.remove('on');
          }
        }
      })
      .catch(function () {
        failures++;
        if (failures === 5) {
          $('waitNote').textContent = 'Lost contact with the server. Tell the organiser — '
                                    + 'this screen will reconnect on its own.';
        }
      });
  }

  poll();
  setInterval(poll, POLL_MS);
})();
