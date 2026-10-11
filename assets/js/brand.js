/* Paints the brand mark into every [data-logo] slot at the size it asks for.
   Set LOGO_PNG to a file path (or a data: URL) and the artwork replaces the
   vector emblem everywhere at once. */
var LOGO_PNG = '';

function applyLogo() {
  var slots = document.querySelectorAll('[data-logo]');
  for (var i = 0; i < slots.length; i++) {
    var el = slots[i], px = +el.getAttribute('data-logo');
    var sm = px < 48, w = Math.round(px * (sm ? 100 / 156 : 172 / 156));
    el.innerHTML = LOGO_PNG
      ? '<img class="mk" src="' + LOGO_PNG + '" alt="CSS Arena" style="height:' + px + 'px;width:auto">'
      : '<svg class="mk" width="' + w + '" height="' + px + '"><use href="#' +
        (sm ? 'mark-sm' : 'mark') + '"/></svg>';
    el.classList.add('haslogo');
  }
  var hide = document.querySelectorAll('[data-logo-hide]');
  for (var j = 0; j < hide.length; j++) hide[j].style.display = 'none';
}
applyLogo();
