<?php
/* ────────────────────────────────────────────────────────────────
   THE EVALUATION RULES. All of them, in one place.

     The best design wins. Time is a limit, not a score.

   Rounds 1 and 2 : accuracy alone. Equal accuracy is an equal place.
                    Top N advance, plus everyone tied with the last
                    qualifying place. Must reach ACCURACY_FLOOR.
   Round 3        : accuracy, then fewer characters, then earlier
                    submission, then joint placing. No minimum to win.
   ──────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/../config.php';

function acc_bucket($accuracy) {
    return (int) round($accuracy / TIE_BAND);
}

/* Two entries share a place when nothing left in the rule can separate them. */
function same_place($a, $b, $charsFirst) {
    if ($a['bucket'] !== $b['bucket']) return false;
    if (!$charsFirst) return true;
    return $a['char_count'] === $b['char_count']
        && $a['elapsed_ms']  === $b['elapsed_ms'];
}

/**
 * $rows          submissions already scored, any order
 * $charsFirst    true in round 3
 * $qualifyCount  how many should advance; 0 for the final
 *
 * Adds place / qualified to every row and returns them in ranked order.
 */
function rank_round(array $rows, $charsFirst, $qualifyCount) {
    foreach ($rows as &$r) $r['bucket'] = acc_bucket($r['accuracy']);
    unset($r);

    usort($rows, function ($a, $b) use ($charsFirst) {
        if ($a['bucket'] !== $b['bucket']) return $b['bucket'] <=> $a['bucket'];
        if (!$charsFirst) return 0;                       // rounds 1-2 stop here
        if ($a['char_count'] !== $b['char_count'])
            return $a['char_count'] <=> $b['char_count'];
        return $a['elapsed_ms'] <=> $b['elapsed_ms'];
    });

    /* competition ranking: 1, 2, 2, 4 */
    $place = 0;
    foreach ($rows as $i => &$r) {
        if ($i === 0 || !same_place($r, $rows[$i - 1], $charsFirst)) $place = $i + 1;
        $r['place'] = $place;
    }
    unset($r);

    /* The cut. If it lands inside a tie, everyone in that tie comes with it —
       nobody is dropped by something that is not the design. */
    $cutPlace = 0;
    if ($qualifyCount > 0 && $rows) {
        $idx = min($qualifyCount, count($rows)) - 1;
        $cutPlace = $rows[$idx]['place'];
    }
    foreach ($rows as &$r) {
        $r['qualified'] = $qualifyCount > 0
            && $r['place'] <= $cutPlace
            && $r['accuracy'] >= ACCURACY_FLOOR;
    }
    unset($r);

    return $rows;
}

/* A participant's own deadline. Extra time granted after a PC failure moves
   this, and nothing else — it never touches anybody's score. */
function deadline_ms($roundStartedMs, $timeLimitS, $extraMs) {
    return $roundStartedMs + $timeLimitS * 1000 + (int) $extraMs;
}
