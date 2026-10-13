/* Registration-screen demo: a five-line snippet types itself once and the
   shape beside it takes form line by line, with the accuracy climbing to
   100%. Runs once on load, then holds still. Pure decoration — nothing on
   the page depends on it, and it never runs inside the arena. */
(function () {
  var pre = document.getElementById('demoCode'),
      shape = document.getElementById('demoShape'),
      acc = document.getElementById('demoAcc');
  if (!pre || !shape) return;

  var LINES = [
    ['div{',                     null,            '0%'],
    ['  width:96px;',            ['width','96px'],  '31%'],
    ['  height:96px;',           ['height','96px'], '58%'],
    ['  background:#F2C14E;',    ['background','#F2C14E'], '86%'],
    ['  border-radius:50%;',     ['borderRadius','50%'],   '100%'],
    ['}',                        null,            null]
  ];

  function paint(line) {
    return line
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/^(\s*)([a-z-]+)(:)/, '$1<i class="p">$2</i><i class="x">$3</i>')
      .replace(/(#[0-9A-Fa-f]{3,6}|\d+)(px|%)?/g, '<i class="n">$1$2</i>')
      .replace(/^div/, '<i class="s">div</i>');
  }

  function finish() {
    pre.innerHTML = LINES.map(function (l) { return paint(l[0]); }).join('\n');
    LINES.forEach(function (l) { if (l[1]) shape.style[l[1][0]] = l[1][1]; });
    acc.textContent = '100%'; acc.classList.add('ok');
  }

  if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return finish();

  var done = [], li = 0, ci = 0;
  function step() {
    if (li >= LINES.length) { pre.classList.add('idle'); return; }
    var line = LINES[li][0];
    ci++;
    pre.innerHTML = done.concat(paint(line.slice(0, ci)) + '<b class="cur"></b>').join('\n');
    if (ci < line.length) return setTimeout(step, 34);
    var fx = LINES[li][1];
    if (fx) shape.style[fx[0]] = fx[1];
    if (LINES[li][2]) { acc.textContent = LINES[li][2]; acc.classList.toggle('ok', LINES[li][2] === '100%'); }
    done.push(paint(line)); li++; ci = 0;
    setTimeout(step, 260);
  }
  setTimeout(step, 900);
})();
