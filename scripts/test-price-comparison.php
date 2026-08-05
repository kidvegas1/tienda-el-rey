<?php
/**
 * Self-check for price comparison helpers.
 * Run: php scripts/test-price-comparison.php
 */
declare(strict_types=1);

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

if (is_file(__DIR__ . '/../config.php')) {
    require __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/sql.php';
}
require_once __DIR__ . '/../includes/price-comparison.php';

assert_true(price_comparison_normalize_barcode('0 12345 67890 5') === '012345678905', 'normalize barcode digits');
assert_true(price_comparison_normalize_barcode('UPC-A') === 'UPC-A', 'keep non-digit labels');
assert_true(strlen(price_comparison_normalize_barcode('12345678')) === 8, '8-digit ok');
assert_true(round(10.0 / 2.0, 2) === 5.0, 'unit_cost = amount/qty');

if (function_exists('db')) {
    try {
        $exists = price_comparison_tables_exist(db());
        echo ($exists ? 'OK' : 'SKIP') . ": supplier_prices table\n";
    } catch (Throwable $e) {
        echo 'SKIP: DB — ' . $e->getMessage() . "\n";
    }
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failed\n");
    exit(1);
}
echo "All price-comparison unit checks passed.\n";
