/* ────────────────────────────────────────────────────────────────
   The organiser's console. Polls admin/state.php once a second and paints
   what it finds. Every action is a POST carrying the session token.
   ──────────────────────────────────────────────────────────────── */
(function () {
  var clockOffset = 0;
  function serverNow() { return Date.now() + clockOffset; }
  function $(id) { return document.getElementById(id); }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function mmss(sec) {
    if (sec < 0) sec = 0;
    var m = Math.floor(sec / 60), s = Math.floor(sec % 60);
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  function post(action, extra) {
    var body = new FormData();
    body.append('do', action);
    body.append('token', ADMIN_TOKEN);
    for (var k in extra) if (extra.hasOwnProperty(k)) body.append(k, extra[k]);
    return fetch('action.php', { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) alert(d.error || 'That did not work.');
        poll();
        return d;
      });
  }

  var LEVEL = { easy: 'Easy', medium: 'Intermediate', hard: 'Final' };

  function paintRounds(rounds, current) {
    var html = '';
    rounds.forEach(function (r) {
      var dot = r.status === 'running' ? 'run' : (r.status === 'ended' ? 'end' : 'pend');
      var meta, acts;

      if (r.status === 'running') {
        var left = r.ends_ms ? Math.round((r.ends_ms - serverNow()) / 1000) : 0;
        var toGo = r.started_ms && r.started_ms > serverNow();
        meta = toGo ? 'starting in ' + Math.ceil((r.started_ms - serverNow()) / 1000) + 's'
                    : LEVEL[r.difficulty] + ' · ' + mmss(left) + ' left · ' + who(r);
        acts = '<button class="btn sm danger" data-end="' + r.no + '">End now</button>';
      } else if (r.status === 'ended') {
        meta = LEVEL[r.difficulty] + ' · finished';
        acts = '<button class="btn sm ghost" data-start="' + r.no + '">Run again</button>';
      } else {
        meta = LEVEL[r.difficulty] + ' · ' + mmss(r.time_limit_s) + ' · ' + who(r);
        acts = '<button class="btn sm" data-start="' + r.no + '">Start Round ' + r.no + '</button>';
      }

      html += '<div class="card rcard" data-round="' + r.no + '">'
            +   '<div class="dot ' + dot + '"></div>'
            +   '<div class="info"><b class="rname">Round ' + r.no + '</b>'
            +     '<span class="rmeta">' + esc(meta) + '</span></div>'
            +   '<div class="acts">' + acts + '</div>'
            + '</div>';
    });
    $('roundCards').innerHTML = html;
  }

  /* who this round admits — the thing worth knowing before pressing Start */
  function who(r) {
    if (r.no === 1) return 'everyone';
    return r.qualifiers ? r.qualifiers + ' qualified' : 'everyone (no qualifiers set)';
  }

  var STATE_LABEL = {
    waiting:   '<span style="color:var(--mut)">waiting</span>',
    working:   '<span style="color:var(--warn)">working</span>',
    submitted: '<span style="color:var(--ok)">submitted</span>',
    offline:   '<span style="color:var(--bad)">offline</span>'
  };

  function paintPeople(people) {
    if (!people.length) {
      $('live').innerHTML = '<tr><td colspan="6" class="dim" style="padding:16px">Nobody has registered yet.</td></tr>';
      return;
    }
    var html = '';
    people.forEach(function (p) {
      var credit = p.credit_ms ? '+' + Math.round(p.credit_ms / 60000) + ' min' : '—';
      html += '<tr>'
            + '<td>' + esc(p.name)
            +   (p.email ? '<div class="dim" style="font:11px/1.4 var(--mono);margin-top:2px">'
                           + esc(p.email) + '</div>' : '')
            + '</td>'
            + '<td class="dim">' + esc(p.seat || '—') + '</td>'
            + '<td>' + (p.chars == null ? '—' : p.chars) + '</td>'
            + '<td>' + (STATE_LABEL[p.state] || p.state) + '</td>'
            + '<td class="dim">' + credit + '</td>'
            + '<td style="text-align:right;white-space:nowrap">'
            +   '<button class="btn sm ghost" data-credit="' + p.id + '">+5 min</button> '
            +   '<button class="btn sm ghost" data-release="' + p.id + '" title="Send this PC back to the registration screen">Release</button>'
            + '</td>'
            + '</tr>';
    });
    $('live').innerHTML = html;
  }

  /* ---------- scoring ----------
     Fires a few requests at once and keeps going until the queue is empty.
     Each request scores one entry, so the work spreads across however many
     the host PC can render at the same time. */
  var scoring = false, scoreRound = 0;

  function scoreQueue(no) {
    if (scoring) return;
    scoring = true; scoreRound = no;
    var inFlight = 0, stop = false;

    function pump() {
      if (stop) { if (inFlight === 0) { scoring = false; poll(); } return; }
      while (inFlight < 3) {
        inFlight++;
        var body = new FormData();
        body.append('round', no);
        body.append('token', ADMIN_TOKEN);
        fetch('score.php', { method: 'POST', body: body })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            inFlight--;
            if (!d || !d.ok || d.done) stop = true;
            if (d && typeof d.queued === 'number') {
              $('qNote').textContent = d.scored + ' of ' + d.total + ' scored'
                                     + (d.errors ? ' · ' + d.errors + ' failed' : '');
              $('qFill').style.width = d.total ? Math.round(d.scored / d.total * 100) + '%' : '0';
            }
            pump();
          })
          .catch(function () { inFlight--; stop = true; pump(); });
      }
    }
    $('qLabel').textContent = 'Scoring Round ' + no + '…';
    pump();
  }

  function paintProgress(d) {
    var r = null;
    d.rounds.forEach(function (x) { if (x.no === d.current) r = x; });
    var c = d.counts;

    $('svReg').textContent = c.registered;
    $('svOn').textContent  = c.online;
    $('hdCount').textContent = c.online + ' of ' + c.registered;
    if ($('stReg')) {
      $('stReg').textContent = c.registered;
      $('stOn').textContent  = c.online;
      $('stSub').textContent = c.submitted == null ? '—' : c.submitted;
      var run = null, fin = null;
      d.rounds.forEach(function (x) { if (x.status === 'running') run = x; if (x.status === 'ended') fin = x; });
      var tl = $('stRound').parentNode;
      if (run) {
        $('stRound').textContent = run.no;
        $('stRoundS').textContent = mmss(Math.max(0, Math.round((run.ends_ms - serverNow()) / 1000))) + ' left';
      } else {
        $('stRound').textContent = fin ? fin.no : '—';
        $('stRoundS').textContent = fin ? 'finished' : 'idle';
      }
      tl.classList.toggle('running', !!run);
    }
    $('qErr').textContent = c.errors ? c.errors + ' errors' : '';

    if (scoring) return;              // the scorer owns this box while it runs

    if (!r || r.status !== 'running') {
      var ended = null;
      d.rounds.forEach(function (x) { if (x.status === 'ended') ended = x; });
      if (ended) {
        $('qLabel').textContent = 'Round ' + ended.no + ' finished';
        $('qNote').innerHTML = 'Press <b>Score this round</b> to render and compare every entry.';
        $('qFill').style.width = '0';
        $('scoreBtn').style.display = '';
        $('scoreBtn').dataset.score = ended.no;
        $('resultsBtn').style.display = '';
        $('resultsBtn').href = '../results.php?round=' + ended.no;
      } else {
        $('qLabel').textContent = 'Nothing running';
        $('qFill').style.width = '0';
        $('qNote').textContent = 'Start a round below and every waiting PC will count down together.';
        $('scoreBtn').style.display = 'none';
        $('resultsBtn').style.display = 'none';
      }
      return;
    }
    $('scoreBtn').style.display = 'none';
    $('resultsBtn').style.display = 'none';
    var left  = Math.max(0, Math.round((r.ends_ms - serverNow()) / 1000));
    var total = r.time_limit_s;
    var gone  = Math.max(0, Math.min(1, (total - left) / total));
    $('qLabel').textContent = 'Round ' + r.no + ' · ' + mmss(left) + ' left';
    $('qFill').style.width  = Math.round(gone * 100) + '%';
    $('qNote').textContent  = c.submitted + ' of ' + c.registered + ' submitted';
  }

  var last = null;

  function poll() {
    fetch('state.php', { cache: 'no-store' })
      .then(function (r) {
        if (r.status === 401) { window.location.href = 'login.php'; return null; }
        return r.json();
      })
      .then(function (d) {
        if (!d) return;
        clockOffset = d.now - Date.now();
        last = d;
        paintRounds(d.rounds, d.current);
        paintPeople(d.people);
        paintProgress(d);
      })
      .catch(function () { /* Apache hiccup — the next tick will catch up */ });
  }

  /* clicks, caught once at the top rather than rebound on every repaint */
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('button') : null;
    if (!b) return;

    if (b.dataset.start) {
      var no = b.dataset.start;
      var mins = prompt('Round ' + no + ' — how many minutes?',
                        last ? Math.round(roundOf(no).time_limit_s / 60) : 10);
      if (mins === null) return;
      mins = parseInt(mins, 10);
      if (!(mins >= 1 && mins <= 120)) { alert('Between 1 and 120 minutes.'); return; }
      var r2 = roundOf(no);
      var msg = 'Start Round ' + no + '?\n\nGoes to: ' + who(r2)
              + '\nLength: ' + mins + ' minutes'
              + '\n\nEvery PC gets a 5 second countdown.';
      if (!confirm(msg)) return;
      post('start_round', { round: no, time_limit_s: mins * 60 });
    }
    else if (b.dataset.end) {
      if (confirm('End Round ' + b.dataset.end + ' now for everyone?')) post('end_round', { round: b.dataset.end });
    }
    else if (b.dataset.release) {
      if (confirm('Send this PC back to the registration screen?\n\n'
                + 'Their work is kept — typing the same name again brings it back.\n'
                + 'Use this to hand a machine to a different student.')) {
        post('drop_participant', { participant_id: b.dataset.release });
      }
    }
    else if (b.dataset.credit) {
      post('extra_time', { participant_id: b.dataset.credit, minutes: 5 });
    }
    else if (b.id === 'scoreBtn') {
      scoreQueue(+b.dataset.score);
    }
    else if (b.id === 'btnReset') {
      if (confirm('Put every round back to not-started?\n\nParticipants and their work are kept.')) post('reset_rounds', {});
    }
    else if (b.id === 'btnFullReset') {
      if (confirm('Delete every participant and every submission?\n\nThis cannot be undone.')
       && confirm('Really? Everything goes.')) post('full_reset', {});
    }
  });

  function roundOf(no) {
    var out = { time_limit_s: 600 };
    if (last) last.rounds.forEach(function (r) { if (String(r.no) === String(no)) out = r; });
    return out;
  }

  poll();
  setInterval(poll, 1000);
})();
