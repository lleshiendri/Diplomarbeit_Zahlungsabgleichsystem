<?php

function sanitize_albanian($text) {
    $text = mb_strtoupper($text, 'UTF-8');
    $text = str_replace(['Ë', 'Ç'], ['E', 'C'], $text);
    return $text;
}

function generateReferenceID($studentId, $firstname, $lastname) {
    $first_clean = sanitize_albanian($firstname);
    $last_clean  = sanitize_albanian($lastname);

    $first3 = substr($first_clean, 0, 3);
    $last3  = substr($last_clean, 0, 3);

    $letterIndex = ($studentId * 7) % 26;
    $checksumLetter = chr(65 + $letterIndex);

    $checksumDigit = ($studentId * 3) % 10;

    return "HTL-$first3$last3{$studentId}-$checksumLetter$checksumDigit";
}


if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    echo "Reference ID Generator Demo" . PHP_EOL;
    echo str_repeat('=', 50) . PHP_EOL;
    echo generateReferenceID(1, "Arlind", "Gashi") . PHP_EOL;
    echo generateReferenceID(2, "Di", "Ro") . PHP_EOL;
    echo generateReferenceID(3, "Çela", "Ëndrit") . PHP_EOL;
    echo generateReferenceID(4, "Arlind123", "Gashi-") . PHP_EOL;
}



