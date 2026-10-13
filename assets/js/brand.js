/* Paints the brand mark into every [data-logo] slot at the size it asks for.
   Set LOGO_PNG to a file path (or a data: URL) and the artwork replaces the
   vector emblem everywhere at once. */
var LOGO_PNG = '';

/* The mark is written out in full rather than through <use>, so each part
   (chevrons, shield, crown, the S) can be animated on its own. */
function inlineMark(id, w, h) {
  var sym = document.getElementById(id), core = document.getElementById('core');
  if (!sym || !core) return '';
  var body = sym.innerHTML.replace(/<use[^>]*href="#core"[^>]*>(<\/use>)?/, core.innerHTML);
  return '<svg class="mk' + (id === 'mark' ? ' mk-lg' : '') + '" width="' + w + '" height="' + h +
         '" viewBox="' + sym.getAttribute('viewBox') + '" aria-label="CSS Arena">' + body + '</svg>';
}

function applyLogo() {
  var slots = document.querySelectorAll('[data-logo]');
  for (var i = 0; i < slots.length; i++) {
    var el = slots[i], px = +el.getAttribute('data-logo');
    var sm = px < 48, w = Math.round(px * (sm ? 100 / 156 : 172 / 156));
    el.innerHTML = LOGO_PNG
      ? '<img class="mk" src="' + LOGO_PNG + '" alt="CSS Arena" style="height:' + px + 'px;width:auto">'
      : inlineMark(sm ? 'mark-sm' : 'mark', w, px);
    el.classList.add('haslogo');
  }
  var hide = document.querySelectorAll('[data-logo-hide]');
  for (var j = 0; j < hide.length; j++) hide[j].style.display = 'none';
}
applyLogo();
