<?php
/**
 * Smoke checks for remittance exchange-rate helpers.
 * Run: php scripts/test-exchange-rates.php
 */
declare(strict_types=1);

require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/exchange-rates.php';

$failures = 0;
function assert_true(bool $cond, string $label): void {
    global $failures;
    if ($cond) {
        echo "PASS: $label\n";
        return;
    }
    $failures++;
    echo "FAIL: $label\n";
}

assert_true(exchange_rates_flag_slug('MX') === 'mexico', 'flag slug MX');
assert_true(exchange_rates_flag_slug('SV') === 'el-salvador', 'flag slug SV');

$pdo = db();
assert_true(exchange_rates_table_exists($pdo), 'table exists');
$all = exchange_rates_list($pdo, false);
assert_true(count($all) >= 10, 'seeded countries present');
$pub = exchange_rates_list($pdo, true);
assert_true(is_array($pub), 'published list returns array');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed\n");
    exit(1);
}
echo "All exchange-rate tests passed. total=" . count($all) . " published=" . count($pub) . "\n";
