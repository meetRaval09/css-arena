<?php
/* HTML and CSS only.

   Note that this is a courtesy check, not the enforcement. The real
   enforcement is the renderer: a <script> simply never runs when the server
   screenshots the page, so writing one gains nothing. Nobody is disqualified
   for it — the rule enforces itself. */

function code_problem($code) {
    $banned = '/<script|<iframe|<object|<embed|<link|<meta|<base'
            . '|\son\w+\s*=|javascript:|@import|url\(\s*[\'"]?https?:/i';
    if (preg_match($banned, $code)) {
        return 'HTML and CSS only — no scripts, iframes or external files.';
    }
    return null;
}

/* Characters are counted the way the rules say: whitespace counts, comments
   are free, and a comment takes its whole line with it so the round-2 and 3
   hint block costs exactly nothing. */
function countable_length($code) {
    $s = preg_replace('/[ \t]*<!--[\s\S]*?(?:-->|$)[ \t]*\r?\n?/', '', $code);
    $s = preg_replace('/[ \t]*\/\*[\s\S]*?(?:\*\/|$)[ \t]*\r?\n?/', '', $s);
    $s = preg_replace('/^\s+/', '', $s);
    $s = str_replace("\r\n", "\n", $s);
    return mb_strlen($s, 'UTF-8');
}
