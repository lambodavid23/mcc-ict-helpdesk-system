<?php
/**
 * Password generator shared by the command-line account tools.
 *
 * Produces a password that satisfies a complexity policy on the first try:
 * at least one lower case, upper case, digit and symbol character, drawn
 * from a set with no visually ambiguous glyphs (no 0/O, 1/l/I).
 */
function generatePassword($length = 20) {
    $lower  = 'abcdefghijkmnopqrstuvwxyz';
    $upper  = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $digit  = '23456789';
    $symbol = '!@#$%^&*-_=+';
    $all    = $lower . $upper . $digit . $symbol;

    $chars = '';
    foreach ([$lower, $upper, $digit, $symbol] as $pool) {
        $chars .= $pool[random_int(0, strlen($pool) - 1)];
    }
    for ($i = strlen($chars); $i < $length; $i++) {
        $chars .= $all[random_int(0, strlen($all) - 1)];
    }

    // Shuffle, so the guaranteed characters are not always in the first four
    // positions, which is the first thing a pattern-matching policy looks at.
    return str_shuffle($chars);
}
