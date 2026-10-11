<?php
/* Reads the colours out of a rendered PNG, so the swatches shown to
   participants can never disagree with the picture they are looking at.
   Near-identical colours are folded together so an anti-aliased edge cannot
   invent a swatch that is not really in the design. */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/diff.php';

function palette_from_png($path, $max = 8) {
    $im = load_png($path);
    if (!$im) return [];

    $w = CANVAS_W; $h = CANVAS_H; $n = $w * $h;
    $count = [];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y) & 0xFFFFFF;
            $count[$c] = ($count[$c] ?? 0) + 1;
        }
    }
    imagedestroy($im);
    arsort($count);

    $out = [];
    foreach ($count as $c => $freq) {
        if (count($out) >= $max) break;
        if ($freq / $n < 0.004) break;                 // too rare to be real
        $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
        $near = false;
        foreach ($out as $o) {
            if (abs($o[0] - $r) <= 16 && abs($o[1] - $g) <= 16 && abs($o[2] - $b) <= 16) {
                $near = true; break;
            }
        }
        if (!$near) $out[] = [$r, $g, $b];
    }
    return array_map(function ($c) {
        return strtoupper(sprintf('#%02X%02X%02X', $c[0], $c[1], $c[2]));
    }, $out);
}
