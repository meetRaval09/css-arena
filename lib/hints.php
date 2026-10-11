<?php
/* Rounds 2 and 3 get a nudge: the CSS properties the design actually uses,
   with the everyday ones filtered out. Names only and never values, so it
   points at the technique without handing over the geometry.
   Round 1 gets nothing. */

$COMMON_PROPS = [
    'margin','margin-top','margin-right','margin-bottom','margin-left',
    'padding','padding-top','padding-right','padding-bottom','padding-left',
    'width','height','min-width','min-height','max-width','max-height',
    'background','background-color','color','display','position',
    'top','right','bottom','left','inset','box-sizing','overflow','content',
    'font','font-size','font-family','font-weight','line-height','text-align',
    'place-items','place-content','align-items','justify-content',
    'justify-items','align-content',
];

function hints_from_code($code) {
    global $COMMON_PROPS;

    $css = '';
    if (preg_match_all('/<style[^>]*>([\s\S]*?)(?:<\/style>|$)/i', $code, $m)) {
        $css .= implode("\n", $m[1]);
    }
    if (preg_match_all('/style\s*=\s*"([^"]*)"/i', $code, $m)) {
        $css .= ';' . implode(';', $m[1]);
    }
    $css = preg_replace('/\/\*[\s\S]*?\*\//', '', $css);

    $found = [];
    if (preg_match_all('/(?:^|[;{}])\s*(-{0,2}[a-zA-Z][\w-]*)\s*:/m', $css, $m)) {
        foreach ($m[1] as $p) {
            $p = strtolower($p);
            if (in_array($p, $COMMON_PROPS, true)) continue;
            /* the four sides of a border collapse into one entry, not four */
            $p = preg_replace('/^border-(top|right|bottom|left)-/', 'border-*-', $p);
            $found[$p] = true;
        }
    }

    foreach (['linear-gradient', 'radial-gradient', 'conic-gradient'] as $fn) {
        if (strpos($css, $fn) !== false) $found[$fn] = true;
    }

    if (isset($found['transform']) &&
        preg_match_all('/\b(rotate|skewX|skewY|skew|scaleX|scaleY|scale|translateX|translateY|translate|matrix|perspective)\s*\(/i', $css, $m)) {
        $fns = array_values(array_unique(array_map('strtolower', $m[1])));
        sort($fns);
        unset($found['transform']);
        $found['transform (' . implode(', ', $fns) . ')'] = true;
    }

    $list = array_keys($found);
    sort($list);
    return array_slice($list, 0, 12);
}

/* The comment block placed at the top of the editor in rounds 2 and 3.
   Comments cost nothing, so the hint is free. */
function hint_block(array $list) {
    if (!$list) return '';
    $lines = []; $line = '';
    foreach ($list as $h) {
        if ($line !== '' && strlen($line . ', ' . $h) > 56) { $lines[] = $line; $line = $h; }
        else $line = $line === '' ? $h : $line . ', ' . $h;
    }
    if ($line !== '') $lines[] = $line;

    $out = "<!-- HINT · properties this design uses -->\n";
    foreach ($lines as $l) $out .= '<!-- ' . $l . " -->\n";
    $out .= "<!-- Names only. The values are yours to work out. -->";
    return $out;
}
