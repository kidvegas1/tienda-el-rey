<?php
/**
 * Regression: client activity, transfer security rules, txn analytics helpers.
 * Run: php scripts/test-client-security.php
 */
declare(strict_types=1);

require __DIR__ . '/../includes/client-activity.php';
require __DIR__ . '/../includes/transfer-security.php';
require __DIR__ . '/../includes/transaction-analytics.php';

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

echo "== Unit: helper presence ==\n";
assert_true(function_exists('client_activity_log'), 'client_activity_log exists');
assert_true(function_exists('client_activity_list'), 'client_activity_list exists');
assert_true(function_exists('transfer_security_scan_transfer'), 'transfer_security_scan_transfer exists');
assert_true(function_exists('transfer_security_open_count_by_severity'), 'open_count_by_severity exists');
assert_true(function_exists('transfer_security_open_for_client'), 'open_for_client exists');
assert_true(function_exists('txn_analytics_summary'), 'txn_analytics_summary exists');
assert_true(function_exists('txn_analytics_patterns'), 'txn_analytics_patterns exists');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} unit test(s) failed\n");
    exit(1);
}

echo "All unit tests passed.\n\n";

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();

echo "== Integration: schema + queries ==\n";

$hasActivity = client_activity_table_exists($pdo);
$hasSecurity = transfer_security_table_exists($pdo);
echo ($hasActivity ? 'OK' : 'SKIP') . ": client_activity_log table\n";
echo ($hasSecurity ? 'OK' : 'SKIP') . ": transfer_security_alerts table\n";

$storeId = (int)$pdo->query('SELECT id FROM stores ORDER BY id LIMIT 1')->fetchColumn();
$dateTo = date('Y-m-d');
$dateFrom = date('Y-m-d', strtotime('-90 days'));

try {
    $summary = txn_analytics_summary($pdo, $storeId > 0 ? $storeId : null, $dateFrom, $dateTo);
    assert_true(isset($summary['count'], $summary['principal'], $summary['unique_clients']), 'txn summary keys');
    echo 'PASS: txn_analytics_summary count=' . (int)$summary['count'] . "\n";

    $patterns = txn_analytics_patterns($pdo, $storeId > 0 ? $storeId : null, $dateFrom, $dateTo);
    assert_true(isset($patterns['avg_amount'], $patterns['multi_store_clients_count']), 'txn patterns keys');
    echo "PASS: txn_analytics_patterns\n";

    $counts = transfer_security_open_count_by_severity($pdo);
    assert_true(isset($counts['low'], $counts['medium'], $counts['high']), 'severity count keys');
    echo "PASS: open_count_by_severity\n";
} catch (Throwable $e) {
    $failures++;
    echo 'FAIL: analytics/security query — ' . $e->getMessage() . "\n";
}

if ($hasActivity && $hasSecurity && $storeId > 0) {
    $pdo->beginTransaction();
    try {
        $userId = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $clientIns = $pdo->prepare(
            "INSERT INTO clients (name, phone, monthly_limit, notes) VALUES (?, ?, ?, ?)"
        );
        $marker = 'TDD-SEC-' . bin2hex(random_bytes(3));
        $clientIns->execute([$marker, '5550001111', 3000, 'security test']);
        $clientId = (int)(function_exists('sql_last_insert_id')
            ? sql_last_insert_id($pdo, 'clients')
            : $pdo->lastInsertId());

        client_activity_log($pdo, $clientId, 'client_updated', 'test event', $userId, $storeId, ['test' => true]);
        $activity = client_activity_list($pdo, $clientId, 5);
        assert_true(count($activity) >= 1, 'activity log row created');

        $alertId = transfer_security_create_alert($pdo, [
            'alert_type'  => 'frequency_spike',
            'severity'   => 'medium',
            'client_id'   => $clientId,
            'store_id'    => $storeId,
            'title'       => 'Test alert',
            'details'     => 'regression',
        ]);
        assert_true($alertId !== null && $alertId > 0, 'alert created');
        $open = transfer_security_open_for_client($pdo, $clientId);
        assert_true(count($open) >= 1, 'open alert listed for client');

        $pdo->rollBack();
        echo "PASS: activity + alert lifecycle rolled back\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $failures++;
        echo 'FAIL: lifecycle — ' . $e->getMessage() . "\n";
    }
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed\n");
    exit(1);
}

echo "\nAll client-security tests passed.\n";
