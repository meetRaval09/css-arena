/* ────────────────────────────────────────────────────────────────
   The code editor. Lifted from the approved prototype so it behaves exactly
   as it did there: syntax colours, real indentation, auto-closing tags and
   braces, an autocomplete list, and a character count that ignores comments.

   A transparent <textarea> sits on top of a coloured <pre>. No library, so
   nothing to download and nothing to break when the lab is offline.
   ──────────────────────────────────────────────────────────────── */
var codeEl = document.getElementById('code');
var hlEl   = document.getElementById('hl');
var gnums  = document.getElementById('gnums');
var aline  = document.getElementById('aline');
var outEl  = document.getElementById('out');
var lintEl = document.getElementById('lint');
var acEl   = document.getElementById('ac');
var ovEl   = document.getElementById('overlay');
var palList = document.getElementById('palList');
var LINE_H = 21, PAD_T = 14;

/* what the server rejects at submit — flagged here so nobody is surprised */
var BANNED = /<script|<iframe|<object|<embed|<link|<meta|<base|\son\w+\s*=|javascript:|@import|url\(\s*['"]?https?:/i;

/* PALETTE and START come from play.php, which reads them out of the database */
var palCursor = 0;

var FREEZE = '<style>*,*::before,*::after{animation-play-state:paused!important;' +
             'animation-delay:0s!important;transition:none!important;' +
             'caret-color:transparent!important}</style>';

/* MUST STAY IDENTICAL to render_shell() in lib/shoot.php — that one produces
   the target and scores every submission. The body rule makes body fill the
   canvas, so background, box-shadow and border on body cover the whole
   picture instead of only the height of whatever happens to be inside it. */
function wrap(code){
  return '<!doctype html><meta charset="utf-8">' +
         '<style>html{width:400px;height:300px;overflow:hidden}' +
         'body{margin:0;width:400px;height:300px}</style>' + code + FREEZE;
}

/* ════════ syntax highlighting ════════
   A transparent <textarea> sits on top of a coloured <pre>. Cheap, offline,
   no library. The real build swaps this for CodeMirror bundled locally. */

function esc(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function sp(cls,t){ return '<span class="'+cls+'">'+esc(t)+'</span>'; }

function hlHTML(s){
  var re = /(<!--[\s\S]*?(?:-->|$))|(<\/?)([a-zA-Z][\w-]*)|(\/?>)|([a-zA-Z-]+)(?=\s*=)|("[^"]*"|'[^']*')/g;
  var out = '', last = 0, m;
  while((m = re.exec(s))){
    out += esc(s.slice(last, m.index));
    if(m[1])      out += sp('c', m[1]);
    else if(m[2] !== undefined) out += sp('p', m[2]) + sp('t', m[3]);
    else if(m[4]) out += sp('p', m[4]);
    else if(m[5]) out += sp('a', m[5]);
    else if(m[6]) out += sp('s', m[6]);
    last = re.lastIndex;
  }
  return out + esc(s.slice(last));
}

function hlCSS(s){
  var re = /(\/\*[\s\S]*?(?:\*\/|$))|(#[0-9a-fA-F]{3,8}\b)|(-?\d*\.?\d+)([a-zA-Z%]*)|([a-zA-Z-]+)(\s*:)|([{}();,:])|("[^"]*"|'[^']*')|([.#]?[a-zA-Z_][\w-]*)/g;
  var out = '', last = 0, m, inBlock = false;
  while((m = re.exec(s))){
    out += esc(s.slice(last, m.index));
    if(m[1])                    out += sp('c', m[1]);
    else if(m[2])               out += sp(inBlock ? 'val' : 'sel', m[2]);
    else if(m[3] !== undefined) out += sp('num', m[3]) + (m[4] ? sp('unit', m[4]) : '');
    else if(m[5])               out += sp(inBlock ? 'prop' : 'sel', m[5]) + sp('p', m[6]);
    else if(m[7]){ if(m[7]==='{') inBlock = true; else if(m[7]==='}') inBlock = false;
                                out += sp('p', m[7]); }
    else if(m[8])               out += sp('s', m[8]);
    else if(m[9])               out += sp(inBlock ? 'val' : 'sel', m[9]);
    last = re.lastIndex;
  }
  return out + esc(s.slice(last));
}

function highlight(src){
  var out = '', i = 0, re = /<style\b[^>]*>/gi, m, low = src.toLowerCase();
  while((m = re.exec(src))){
    out += hlHTML(src.slice(i, m.index)) + hlHTML(m[0]);
    var end = low.indexOf('</style>', re.lastIndex);
    out += hlCSS(src.slice(re.lastIndex, end === -1 ? src.length : end));
    if(end === -1){ return out; }
    out += hlHTML('</style>');
    i = end + 8; re.lastIndex = i;
  }
  return out + hlHTML(src.slice(i));
}

/* ════════ editor ════════ */

/* Comments are stripped before counting — HTML and CSS both. A comment takes its
   whole line with it, and leading blank lines are free, so the round-2 and 3
   hint block costs a participant exactly nothing. */
function countable(code){
  return code.replace(/[ \t]*<!--[\s\S]*?(?:-->|$)[ \t]*\r?\n?/g, '')
             .replace(/[ \t]*\/\*[\s\S]*?(?:\*\/|$)[ \t]*\r?\n?/g, '')
             .replace(/^\s+/, '');
}

var previewTimer = null;
function render(){
  var c = codeEl.value;
  hlEl.innerHTML = highlight(c) + '\n';
  var lines = c.split('\n').length, g = '';
  for(var n = 1; n <= lines; n++) g += '<b>' + n + '</b>';
  gnums.innerHTML = g;
  markActive();
  document.getElementById('chars').textContent = countable(c).length;

  clearTimeout(previewTimer);
  previewTimer = setTimeout(function(){ outEl.srcdoc = wrap(codeEl.value); }, 120);

  if(BANNED.test(c)){
    lintEl.textContent = '⚠  HTML and CSS only — scripts, iframes and external files are rejected at submit.';
    lintEl.classList.add('on');
  } else {
    lintEl.classList.remove('on');
  }
}

function markActive(){
  var line = codeEl.value.slice(0, codeEl.selectionStart).split('\n').length - 1;
  aline.style.top = (PAD_T + line * LINE_H - codeEl.scrollTop) + 'px';
  var kids = gnums.children;
  for(var i = 0; i < kids.length; i++) kids[i].className = (i === line ? 'on' : '');
}

codeEl.addEventListener('scroll', function(){
  hlEl.scrollTop  = codeEl.scrollTop;
  hlEl.scrollLeft = codeEl.scrollLeft;
  gnums.style.transform = 'translateY(' + (-codeEl.scrollTop) + 'px)';
  markActive();
  acClose();
});
codeEl.addEventListener('input', function(){ render(); acShow(); });
['click','keyup','focus'].forEach(function(ev){ codeEl.addEventListener(ev, markActive); });
codeEl.addEventListener('click', acClose);

/* ---- indentation and auto-closing ---- */
var VOID = ['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'];

function inCSS(v, pos){
  var b = v.slice(0, pos).toLowerCase();
  return (b.match(/<style\b/g) || []).length > (b.match(/<\/style>/g) || []).length;
}

function put(value, caret){
  codeEl.value = value;
  codeEl.selectionStart = codeEl.selectionEnd = caret;
  render();
}

codeEl.addEventListener('keydown', function(e){
  /* the completion list eats the navigation keys while it is open */
  if(acOpen){
    if(e.key === 'ArrowDown'){ e.preventDefault(); acMove(1);  return; }
    if(e.key === 'ArrowUp'  ){ e.preventDefault(); acMove(-1); return; }
    if(e.key === 'Enter' || e.key === 'Tab'){ e.preventDefault(); acAccept(); return; }
    if(e.key === 'Escape'){ e.preventDefault(); acClose(); return; }
  }

  var v = codeEl.value, s = codeEl.selectionStart, en = codeEl.selectionEnd;
  var lineStart = v.lastIndexOf('\n', s - 1) + 1;
  var indent = /^[ \t]*/.exec(v.slice(lineStart))[0];

  if(e.key === 'Tab'){
    e.preventDefault();
    if(s !== en || e.shiftKey){                       /* indent / outdent a block */
      var a = v.lastIndexOf('\n', s - 1) + 1;
      var b = v.indexOf('\n', en); if(b === -1) b = v.length;
      var block = v.slice(a, b);
      var next  = e.shiftKey ? block.replace(/^ {1,2}/gm, '') : block.replace(/^/gm, '  ');
      codeEl.value = v.slice(0, a) + next + v.slice(b);
      codeEl.selectionStart = a; codeEl.selectionEnd = a + next.length;
      render();
    } else {
      put(v.slice(0, s) + '  ' + v.slice(en), s + 2);
    }
    return;
  }

  if(e.key === 'Enter'){
    e.preventDefault();
    var before = v.slice(0, s), after = v.slice(en);
    if(/[{([]\s*$/.test(before)){                     /* opened a block → go deeper */
      var inner = indent + '  ';
      if(/^\s*[})\]]/.test(after)){                   /* ...and the closer is next  */
        put(before + '\n' + inner + '\n' + indent + after, s + 1 + inner.length);
      } else {
        put(before + '\n' + inner + after, s + 1 + inner.length);
      }
    } else {
      put(before + '\n' + indent + after, s + 1 + indent.length);
    }
    return;
  }

  if(e.key === '>' && s === en && !inCSS(v, s)){      /* close the tag you just opened */
    var open = /<([a-zA-Z][\w-]*)((?:"[^"]*"|'[^']*'|[^<>"'])*)$/.exec(v.slice(0, s));
    if(open && !/\/\s*$/.test(open[2]) && VOID.indexOf(open[1].toLowerCase()) === -1){
      e.preventDefault();
      put(v.slice(0, s) + '></' + open[1] + '>' + v.slice(en), s + 1);
      return;
    }
  }

  if(e.key === '{' && s === en && inCSS(v, s)){       /* braces come in pairs */
    e.preventDefault();
    put(v.slice(0, s) + '{}' + v.slice(en), s + 1);
    return;
  }

  if(e.key === '}' && s === en){
    if(v.charAt(s) === '}'){                          /* type straight over the pair */
      e.preventDefault();
      put(v, s + 1);
      return;
    }
    var cur = v.slice(lineStart, s);                  /* otherwise pop out one level */
    if(/^[ \t]{2,}$/.test(cur)){
      e.preventDefault();
      var back = cur.slice(0, cur.length - 2);
      put(v.slice(0, lineStart) + back + '}' + v.slice(en), lineStart + back.length + 1);
    }
  }
});

/* ════════ autocomplete ════════
   Prefix match on whatever is being typed, with the list chosen from where the
   caret is: property names inside a rule, values after a colon, tag names after
   a '<', and the round's own palette when a colour is expected. */
var CSS_PROPS = ('align-content align-items align-self animation animation-delay animation-direction ' +
'animation-duration animation-fill-mode animation-iteration-count animation-name animation-timing-function ' +
'aspect-ratio backdrop-filter background background-attachment background-blend-mode background-clip ' +
'background-color background-image background-origin background-position background-repeat background-size ' +
'border border-bottom border-bottom-color border-bottom-left-radius border-bottom-right-radius ' +
'border-bottom-style border-bottom-width border-collapse border-color border-left border-left-color ' +
'border-radius border-right border-right-color border-spacing border-style border-top border-top-color ' +
'border-top-left-radius border-top-right-radius border-width bottom box-shadow box-sizing clip-path color ' +
'column-gap content cursor display filter flex flex-basis flex-direction flex-grow flex-shrink flex-wrap ' +
'float font font-family font-size font-style font-weight gap grid grid-area grid-auto-columns grid-auto-flow ' +
'grid-auto-rows grid-column grid-row grid-template grid-template-areas grid-template-columns ' +
'grid-template-rows height inset justify-content justify-items justify-self left letter-spacing line-height ' +
'margin margin-bottom margin-left margin-right margin-top mask max-height max-width min-height min-width ' +
'mix-blend-mode object-fit opacity order outline outline-color outline-offset overflow padding ' +
'padding-bottom padding-left padding-right padding-top perspective place-content place-items place-self ' +
'position right rotate row-gap scale text-align text-decoration text-shadow text-transform top transform ' +
'transform-origin transition translate vertical-align visibility white-space width word-spacing ' +
'writing-mode z-index').split(' ');

var ALIGN = 'center start end flex-start flex-end stretch baseline space-between space-around space-evenly'.split(' ');
var CSS_VALUES = {
  'display': 'block inline inline-block flex inline-flex grid inline-grid none contents table'.split(' '),
  'position': 'static relative absolute fixed sticky'.split(' '),
  'place-items': ALIGN, 'place-content': ALIGN, 'align-items': ALIGN, 'align-self': ALIGN,
  'justify-content': ALIGN, 'justify-items': ALIGN, 'align-content': ALIGN,
  'flex-direction': 'row row-reverse column column-reverse'.split(' '),
  'flex-wrap': 'nowrap wrap wrap-reverse'.split(' '),
  'overflow': 'visible hidden scroll auto clip'.split(' '),
  'box-sizing': 'content-box border-box'.split(' '),
  'border-style': 'solid dashed dotted double groove ridge none hidden'.split(' '),
  'border': 'solid dashed dotted transparent'.split(' '),
  'background-repeat': 'no-repeat repeat repeat-x repeat-y space round'.split(' '),
  'background-size': 'cover contain auto'.split(' '),
  'background': 'linear-gradient( radial-gradient( conic-gradient( transparent none'.split(' '),
  'background-image': 'linear-gradient( radial-gradient( conic-gradient( none'.split(' '),
  'transform': 'rotate( scale( translate( translateX( translateY( skew( skewX( skewY( matrix( none'.split(' '),
  'filter': 'blur( brightness( contrast( drop-shadow( grayscale( hue-rotate( invert( opacity( saturate( sepia( none'.split(' '),
  'backdrop-filter': 'blur( brightness( saturate( none'.split(' '),
  'clip-path': 'circle( ellipse( polygon( inset( none'.split(' '),
  'mix-blend-mode': 'normal multiply screen overlay darken lighten difference exclusion hue saturation color luminosity'.split(' '),
  'text-align': 'left right center justify'.split(' '),
  'font-weight': 'normal bold lighter bolder 100 300 500 700 900'.split(' '),
  'white-space': 'normal nowrap pre pre-wrap pre-line'.split(' '),
  'visibility': 'visible hidden collapse'.split(' '),
  'object-fit': 'fill contain cover none scale-down'.split(' '),
  'writing-mode': 'horizontal-tb vertical-rl vertical-lr'.split(' '),
  'transform-origin': 'center top bottom left right'.split(' '),
  'text-transform': 'uppercase lowercase capitalize none'.split(' '),
  'text-decoration': 'none underline overline line-through'.split(' ')
};
var GLOBAL_VALUES = 'inherit initial unset revert auto none'.split(' ');
var HTML_TAGS = ('a b br button canvas code div em footer h1 h2 h3 h4 h5 h6 header hr i img input label ' +
  'li main nav ol p pre q s section small span strong style sub sup table td th tr u ul').split(' ');

var acEl = document.getElementById('ac');
var acOpen = false, acItems = [], acIdx = 0, acFrom = 0, acTo = 0;

var CHAR_W = (function(){
  var s = document.createElement('span');
  s.style.cssText = 'position:absolute;visibility:hidden;white-space:pre;font:13px/21px ' +
                    getComputedStyle(codeEl).fontFamily;
  s.textContent = new Array(51).join('0');
  document.body.appendChild(s);
  var w = s.getBoundingClientRect().width / 50;
  document.body.removeChild(s);
  return w || 7.8;
})();

function acContext(){
  var v = codeEl.value, s = codeEl.selectionStart;
  if(s !== codeEl.selectionEnd) return null;
  var before = v.slice(0, s);

  if(!inCSS(v, s)){
    var t = /<([a-zA-Z][\w-]*)?$/.exec(before);
    if(t) return { kind:'tag', word:(t[1] || ''), from:s - (t[1] ? t[1].length : 0) };
    return null;
  }

  var lineStart = before.lastIndexOf('\n') + 1;
  var line = before.slice(lineStart);
  var colon = line.lastIndexOf(':'), stop = Math.max(line.lastIndexOf(';'),
              line.lastIndexOf('{'), line.lastIndexOf('}'));

  if(colon > stop){                                   /* we are writing a value */
    var pm = /([a-zA-Z-]+)\s*:[^:;{}]*$/.exec(line);
    var w = /(#[0-9a-zA-Z]*|[a-zA-Z-]+)$/.exec(before);
    return { kind:'value', prop:(pm ? pm[1].toLowerCase() : ''),
             word:(w ? w[1] : ''), from:s - (w ? w[1].length : 0) };
  }
  var pw = /([a-zA-Z-]+)$/.exec(before);
  if(!pw) return null;
  return { kind:'prop', word:pw[1], from:s - pw[1].length };
}

function acSuggest(ctx){
  var pool;
  if(ctx.kind === 'tag') pool = HTML_TAGS;
  else if(ctx.kind === 'prop') pool = CSS_PROPS;
  else {
    var p = ctx.prop;
    pool = (CSS_VALUES[p] || []).concat(GLOBAL_VALUES);
    if(/color|^background$|shadow|^outline$|^border/.test(p) || ctx.word.charAt(0) === '#'){
      pool = (PALETTE || []).concat(ctx.word.charAt(0) === '#' ? [] : ['transparent','currentColor'])
                            .concat(pool);
    }
  }
  var w = ctx.word.toLowerCase(), out = [], seen = {};
  for(var i = 0; i < pool.length; i++){
    var c = pool[i];
    if(seen[c]) continue;
    if(!w || c.toLowerCase().indexOf(w) === 0){ seen[c] = 1; out.push(c); }
  }
  return out.slice(0, 40);
}

function acShow(){
  if(!document.getElementById('acToggle').checked) return acClose();
  var ctx = acContext();
  if(!ctx || (ctx.kind !== 'tag' && !ctx.word)) return acClose();

  var list = acSuggest(ctx);
  if(!list.length || (list.length === 1 && list[0] === ctx.word)) return acClose();

  acItems = list; acIdx = 0; acFrom = ctx.from; acTo = codeEl.selectionStart;
  var label = ctx.kind === 'tag' ? 'tag' : ctx.kind === 'prop' ? 'prop' : 'value';
  acEl.innerHTML = list.map(function(c, i){
    return '<div class="' + (i === 0 ? 'sel' : '') + '" data-i="' + i + '">' +
           '<span>' + esc(c) + '</span><i>' + label + '</i></div>';
  }).join('');

  var v = codeEl.value, b = v.slice(0, ctx.from);
  var lineNo = b.split('\n').length - 1;
  var col = ctx.from - (b.lastIndexOf('\n') + 1);
  var x = 16 + col * CHAR_W - codeEl.scrollLeft;
  var y = PAD_T + (lineNo + 1) * LINE_H - codeEl.scrollTop + 3;
  var maxX = codeEl.clientWidth - 300;
  acEl.style.left = Math.max(4, Math.min(x, maxX)) + 'px';
  acEl.style.top  = y + 'px';
  acEl.classList.add('on');
  acOpen = true;
}

function acClose(){ acEl.classList.remove('on'); acOpen = false; acItems = []; }

function acMove(d){
  acIdx = (acIdx + d + acItems.length) % acItems.length;
  var kids = acEl.children;
  for(var i = 0; i < kids.length; i++) kids[i].className = (i === acIdx ? 'sel' : '');
  kids[acIdx].scrollIntoView({ block:'nearest' });
}

function acAccept(){
  var pick = acItems[acIdx];
  if(pick === undefined) return acClose();
  var v = codeEl.value;
  acClose();
  put(v.slice(0, acFrom) + pick + v.slice(acTo), acFrom + pick.length);
}

acEl.addEventListener('mousedown', function(e){
  var row = e.target.closest('[data-i]');
  if(!row) return;
  e.preventDefault();
  acIdx = +row.getAttribute('data-i');
  acAccept();
  codeEl.focus();
});
codeEl.addEventListener('blur', function(){ setTimeout(acClose, 120); });
document.getElementById('acToggle').addEventListener('change', function(){
  if(!this.checked) acClose();
  codeEl.focus();
});

/* ════════ the colour palette ════════ */
function copyText(t){
  if(navigator.clipboard && navigator.clipboard.writeText) return navigator.clipboard.writeText(t);
  return new Promise(function(res, rej){
    var ta = document.createElement('textarea');
    ta.value = t; ta.style.cssText = 'position:fixed;top:0;opacity:0';
    document.body.appendChild(ta); ta.select();
    var ok = false; try { ok = document.execCommand('copy'); } catch(err){}
    document.body.removeChild(ta);
    ok ? res() : rej(new Error('copy blocked'));
  });
}

function copySwatch(btn, hex){
  copyText(hex).then(function(){
    var label = btn.querySelector('span');
    btn.classList.add('copied'); label.textContent = 'COPIED';
    setTimeout(function(){ btn.classList.remove('copied'); label.textContent = hex; }, 900);
  }).catch(function(){});
}

function swatchRow(host, cols){
  host.innerHTML = '';
  cols.forEach(function(hex){
    var b = document.createElement('button');
    b.className = 'swatch';
    b.innerHTML = '<i style="background:' + hex + '"></i><span>' + hex + '</span>';
    b.addEventListener('click', function(){ copySwatch(b, hex); });
    host.appendChild(b);
  });
}

function buildPalette() { swatchRow(palList, window.PALETTE || []); }

/* CTRL SHIFT C walks the palette, copying one colour per press */
document.addEventListener('keydown', function (e) {
  var pal = window.PALETTE || [];
  if (e.ctrlKey && e.shiftKey && (e.key === 'C' || e.key === 'c') && pal.length) {
    e.preventDefault(); e.stopPropagation();
    var i = palCursor % pal.length;
    copySwatch(palList.children[i], pal[i]);
    palCursor++;
  }
});

var ovInput = document.getElementById('ov');
if (ovInput) ovInput.addEventListener('input', function (e) { ovEl.style.opacity = e.target.value / 100; });

buildPalette();
