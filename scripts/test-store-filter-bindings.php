<?php
/**
 * Regression: store_filter_sql must use ? placeholders so API param lists match SQL.
 * Run: php scripts/test-store-filter-bindings.php
 */
declare(strict_types=1);

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();
$storeId = 1;
$date = '2026-06-16';
$year = 2026;

$failures = 0;

function internal_ledger_has_status_column(PDO $pdo): bool {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'pgsql') {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'internal_ledger' AND column_name = 'status'"
        );
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'internal_ledger' AND column_name = 'status'"
    );
    $stmt->execute();
    return (bool)$stmt->fetchColumn();
}

function assert_ok(PDO $pdo, string $label, string $sql, array $params): void {
    global $failures;
    try {
        $pdo->prepare($sql)->execute($params);
        echo "PASS: $label\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL: $label — {$e->getMessage()}\n";
    }
}

$storeSql = store_filter_sql('ci.store_id', $storeId);
$sql = 'SELECT 1 FROM clock_ins ci WHERE ' . sql_date('ci.clock_in_time') . ' = ?' . $storeSql;
assert_ok($pdo, 'clock-in today', $sql, [$date, $storeId]);

$storeSql = store_filter_sql('sl.store_id', $storeId);
$sql = "SELECT 1 FROM internal_ledger sl WHERE 1=1{$storeSql} LIMIT 1";
assert_ok($pdo, 'libro-interno list', $sql, [$storeId]);

if (internal_ledger_has_status_column($pdo)) {
    $sql = "SELECT 1 FROM internal_ledger sl WHERE sl.status = 'open'{$storeSql} LIMIT 1";
    assert_ok($pdo, 'libro-interno open store filter', $sql, [$storeId]);
} else {
    echo "SKIP: libro-interno status filter (column missing — run migrate-libro-interno-status.sql)\n";
}

$storeSql = store_filter_sql('store_id', $storeId);
$sql = 'SELECT 1 FROM transfer_statistics WHERE 1=1' . $storeSql . ' AND year = ? LIMIT 1';
assert_ok($pdo, 'statistics year', $sql, [$storeId, $year]);

$storeSql = store_filter_sql('ae.store_id', $storeId);
$sql = "SELECT 1 FROM accounting_entries ae WHERE 1=1{$storeSql} LIMIT 1";
assert_ok($pdo, 'accounting list', $sql, [$storeId]);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} binding test(s) failed\n");
    exit(1);
}

echo "All store-filter binding tests passed.\n";
