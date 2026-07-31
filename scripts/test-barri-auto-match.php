<?php
/**
 * Unit tests for barri_auto_match_store agency/operator normalization.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/report-metadata.php';

$failures = 0;
$assert = static function (bool $cond, string $msg) use (&$failures): void {
    if (!$cond) {
        echo "FAIL: {$msg}\n";
        $failures++;
    }
};

$assert(barri_normalize_agency_number(' a22592 ') === 'A22592', 'normalize agency');
$variants = barri_agency_match_values('A22592');
$assert(in_array('A22592', $variants, true) && in_array('22592', $variants, true), 'agency variants');

$op = barri_parsed_operator_number([
    'transactions' => [['operator' => 'SULY2022']],
]);
$assert($op === 'SULY2022', 'operator from transactions');

echo $failures === 0 ? "OK: barri auto-match helpers passed\n" : "FAILED: {$failures} assertion(s)\n";
exit($failures === 0 ? 0 : 1);
