#!/usr/bin/env php
<?php
/**
 * Unit tests for gemini_sanitize_parsed_report() — run: php scripts/test-gemini-sanitize.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/gemini.php';

$failures = 0;

function assert_same(mixed $expected, mixed $actual, string $label): void {
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "FAIL: {$label}\n  expected: " . json_encode($expected) . "\n  actual:   " . json_encode($actual) . "\n");
    }
}

function assert_true(bool $cond, string $label): void {
    if (!$cond) {
        global $failures;
        $failures++;
        fwrite(STDERR, "FAIL: {$label}\n");
    }
}

// Normalizes US date and strips control chars from agency name
$raw = [
    'agency_number' => "  123\x00  ",
    'agency_name' => "Test Agency",
    'date_from' => '3/5/2026',
    'date_to' => '03/07/2026',
    'currency' => '',
    'beginning_balance' => '$1,234.50',
    'transactions' => [
        [
            'transaction_date' => '2026-03-05',
            'customer_name' => 'Alice',
            'principal' => '100.00',
            'fee' => '5',
            'total' => '105',
        ],
        ['principal' => 0, 'total' => 0],
    ],
    'totals' => ['qty' => 1, 'principal' => 100, 'fee' => 5, 'tax' => 0, 'total' => 105],
];

$out = gemini_sanitize_parsed_report($raw);
assert_same('123', $out['agency_number'], 'agency_number trimmed');
assert_same('2026-03-05', $out['date_from'], 'date_from MM/DD/YYYY');
assert_same('2026-03-07', $out['date_to'], 'date_to normalized');
assert_same('USD', $out['currency'], 'default currency USD');
assert_same(1234.5, $out['beginning_balance'], 'numeric cleanup');
assert_same(1, count($out['transactions']), 'zero-amount rows skipped');
assert_same('Alice', $out['transactions'][0]['customer_name'], 'transaction preserved');
assert_same(105.0, $out['totals']['total'], 'totals from payload');

// Empty input gets safe defaults
$empty = gemini_sanitize_parsed_report([]);
assert_same('Unknown Agency', $empty['agency_name'], 'default agency name');
assert_true($empty['date_to'] !== '', 'date_to fallback to today when empty');
assert_same([], $empty['transactions'], 'no transactions');

// Invalid transactions array
$badTx = gemini_sanitize_parsed_report(['transactions' => 'not-array']);
assert_same([], $badTx['transactions'], 'non-array transactions coerced');

assert_true($failures === 0, 'sanitizer assertions');

assert_same('text/csv', gemini_mime_for_filename('report.csv'), 'csv mime mapping');
assert_same('application/pdf', gemini_mime_for_filename('scan.PDF'), 'pdf mime mapping');
assert_true(gemini_is_text_document('text/csv', 'report.csv'), 'csv treated as text document');
assert_true(!gemini_is_text_document('application/pdf', 'report.pdf'), 'pdf not text document');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test(s) failed.\n");
    exit(1);
}

echo "OK: gemini_sanitize_parsed_report (12 assertions)\n";
exit(0);
