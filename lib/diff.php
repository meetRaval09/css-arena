<?php
/* ────────────────────────────────────────────────────────────────
   Compares two PNGs pixel by pixel.

   Channel weights are the luminance coefficients: the eye reads green far
   more strongly than blue, so a plain RGB distance would score a badly wrong
   green the same as a barely noticeable blue.
   ──────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/../config.php';

function load_png($path) {
    if (!is_file($path)) return null;
    $im = @imagecreatefrompng($path);
    if (!$im) return null;
    imagepalettetotruecolor($im);
    return $im;
}

/**
 * Returns ['raw' => 0-100 similarity, 'exact' => 0-100, 'off' => pixel count]
 * or null if either image could not be read.
 */
function compare_png($pathA, $pathB) {
    $a = load_png($pathA);
    $b = load_png($pathB);
    if (!$a || !$b) return null;

    $w = CANVAS_W; $h = CANVAS_H;
    $n = $w * $h; $sum = 0.0; $exact = 0;

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $p = imagecolorat($a, $x, $y);
            $q = imagecolorat($b, $x, $y);
            $dr = (($p >> 16) & 255) - (($q >> 16) & 255);
            $dg = (($p >>  8) & 255) - (($q >>  8) & 255);
            $db = ( $p        & 255) - ( $q        & 255);
            $sum += sqrt(0.299 * $dr * $dr + 0.587 * $dg * $dg + 0.114 * $db * $db) / 255;
            if (max(abs($dr), abs($dg), abs($db)) <= 8) $exact++;
        }
    }
    imagedestroy($a); imagedestroy($b);

    return [
        'raw'   => 100 * (1 - $sum / $n),
        'exact' => 100 * $exact / $n,
        'off'   => $n - $exact,
    ];
}

/**
 * Rescale a raw similarity so that an empty submission is exactly 0.
 *
 * Any two images share some colour, so a blank canvas already scores well
 * above zero — 36% against one of our test designs. Without this every score
 * sits in a compressed band and the leaderboard hands out a third of a mark
 * for doing nothing.
 */
function rescale($raw, $blankRaw) {
    if (100 - $blankRaw < 0.01) return $raw;
    return max(0, ($raw - $blankRaw) / (100 - $blankRaw) * 100);
}
