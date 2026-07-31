<?php
/**
 * TDD regression: Libro El Rey mark-as-paid (unit + DB integration).
 * Run: php scripts/test-libro-interno-paid.php
 */
declare(strict_types=1);

require __DIR__ . '/../includes/ledger.php';

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

function assert_same(mixed $expected, mixed $actual, string $label): void {
    assert_true($expected === $actual, $label . " (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")");
}

function assert_null(?string $actual, string $label): void {
    assert_true($actual === null, $label);
}

function assert_not_null(?string $actual, string $label): void {
    assert_true($actual !== null, $label);
}

echo "== Unit: ledger settlement rules ==\n";

assert_true(ledger_can_settle_user(['role' => 'admin']), 'admin can settle');
assert_true(ledger_can_settle_user(['role' => 'manager']), 'manager can settle');
assert_true(!ledger_can_settle_user(['role' => 'employee']), 'employee cannot settle');
assert_true(!ledger_can_settle_user(['role' => 'cashier']), 'cashier cannot settle');

assert_true(ledger_valid_status_filter('open'), 'status filter open');
assert_true(ledger_valid_status_filter('paid'), 'status filter paid');
assert_true(ledger_valid_status_filter('all'), 'status filter all');
assert_true(!ledger_valid_status_filter('bogus'), 'reject bogus status filter');

assert_null(ledger_mark_paid_error([
    'status' => 'open',
    'owed_to_store' => 50,
    'store_owes' => 0,
]), 'open entry with owed balance can mark paid');

assert_null(ledger_mark_paid_error([
    'status' => 'open',
    'owed_to_store' => 0,
    'store_owes' => 25,
]), 'open entry with store_owes balance can mark paid');

assert_same('Entry is already paid', ledger_mark_paid_error([
    'status' => 'paid',
    'owed_to_store' => 50,
    'store_owes' => 0,
]), 'reject mark paid when already paid');

assert_same('Entry has no balance to mark as paid', ledger_mark_paid_error([
    'status' => 'open',
    'owed_to_store' => 0,
    'store_owes' => 0,
]), 'reject mark paid with zero balances');

assert_null(ledger_reopen_error(['status' => 'paid']), 'paid entry can reopen');
assert_same('Entry is not paid', ledger_reopen_error(['status' => 'open']), 'reject reopen on open entry');

assert_true(ledger_entry_is_open(['status' => 'open']), 'entry is open');
assert_true(!ledger_entry_is_open(['status' => 'paid']), 'paid entry not open');

assert_same(" AND sl.status = 'open'", ledger_open_status_sql('sl'), 'open status SQL fragment');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} unit test(s) failed\n");
    exit(1);
}

echo "All unit tests passed.\n\n";

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();

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

function assert_ok(PDO $pdo, string $label, string $sql, array $params = [], bool $prepareOnly = false): void {
    global $failures;
    try {
        $stmt = $pdo->prepare($sql);
        if (!$prepareOnly) {
            $stmt->execute($params);
        }
        echo "PASS: $label\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL: $label — {$e->getMessage()}\n";
    }
}

function ledger_open_total(PDO $pdo, int $storeId): float {
    $storeSql = store_filter_sql('store_id', $storeId);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(owed_to_store), 0) FROM internal_ledger WHERE status = 'open'" . $storeSql);
    $stmt->execute([$storeId]);
    return (float)$stmt->fetchColumn();
}

echo "== Integration: schema + SQL + mark paid lifecycle ==\n";

if (!internal_ledger_has_status_column($pdo)) {
    $failures++;
    echo "FAIL: internal_ledger.status column missing — run migrate-libro-interno-status.sql before deploy\n";
    fwrite(STDERR, "{$failures} integration gate(s) failed\n");
    exit(1);
}

$storeId = (int)$pdo->query('SELECT id FROM stores ORDER BY id LIMIT 1')->fetchColumn();
$userId = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
if ($storeId <= 0 || $userId <= 0) {
    $failures++;
    echo "FAIL: need at least one store and user for integration test\n";
    exit(1);
}

$storeSql = store_filter_sql('sl.store_id', $storeId);
$baseWhere = 'WHERE 1=1' . $storeSql;
$params = [$storeId];

$listWhere = $baseWhere . ' AND sl.status = ?';
$listParams = array_merge($params, ['open']);
$sql = 'SELECT sl.*, s.name AS store_name, u.name AS paid_by_name
    FROM internal_ledger sl
    LEFT JOIN stores s ON s.id = sl.store_id
    LEFT JOIN users u ON u.id = sl.paid_by_user_id
    ' . $listWhere . ' LIMIT 1';
assert_ok($pdo, 'list open entries with paid_by join', $sql, $listParams);

$totalsWhere = $baseWhere . ledger_open_status_sql('sl');
$sql = 'SELECT COALESCE(SUM(owed_to_store), 0) AS total_owed_to_store, COALESCE(SUM(store_owes), 0) AS total_store_owes
    FROM internal_ledger sl ' . $totalsWhere;
assert_ok($pdo, 'open-only totals sum', $sql, $params);

$ledgerSql = store_filter_sql('store_id', $storeId);
$sql = "SELECT COALESCE(SUM(owed_to_store), 0) AS owed_to_store FROM internal_ledger WHERE status = 'open'" . $ledgerSql;
assert_ok($pdo, 'dashboard open-only ledger sum', $sql, [$storeId]);

$pdo->beginTransaction();
try {
    $marker = 'TDD-LEDGER-' . bin2hex(random_bytes(4));
    $beforeTotal = ledger_open_total($pdo, $storeId);

    $insert = $pdo->prepare(
        'INSERT INTO internal_ledger (store_id, employee_name, description, owed_to_store, store_owes, entry_date, notes, status)
         VALUES (?, ?, ?, ?, ?, CURRENT_DATE, ?, ?)'
    );
    $insert->execute([$storeId, 'TDD Tester', $marker, 77.77, 0, 'integration test', 'open']);
    $entryId = (int)sql_last_insert_id($pdo, 'internal_ledger');

    assert_true($entryId > 0, 'insert test ledger row');
    assert_true(abs(ledger_open_total($pdo, $storeId) - ($beforeTotal + 77.77)) < 0.01, 'open total increases after insert');

    $entryRow = ['status' => 'open', 'owed_to_store' => 77.77, 'store_owes' => 0];
    assert_null(ledger_mark_paid_error($entryRow), 'integration row passes mark_paid validation');

    $mark = $pdo->prepare(
        'UPDATE internal_ledger SET status = ?, paid_at = ' . sql_now() . ', paid_by_user_id = ? WHERE id = ? AND store_id = ? AND status = ?'
    );
    $mark->execute(['paid', $userId, $entryId, $storeId, 'open']);
    assert_true($mark->rowCount() === 1, 'mark_paid updates exactly one row');

    $check = $pdo->prepare('SELECT status, paid_at, paid_by_user_id, owed_to_store FROM internal_ledger WHERE id = ?');
    $check->execute([$entryId]);
    $paid = $check->fetch();
    assert_same('paid', $paid['status'] ?? null, 'row status is paid after mark_paid');
    assert_not_null($paid['paid_at'] ?? null, 'paid_at is set after mark_paid');
    assert_same($userId, (int)$paid['paid_by_user_id'], 'paid_by_user_id recorded');
    assert_true(abs((float)$paid['owed_to_store'] - 77.77) < 0.01, 'original owed amount preserved when paid');
    assert_true(abs(ledger_open_total($pdo, $storeId) - $beforeTotal) < 0.01, 'open total excludes paid row');

    assert_same('Entry is already paid', ledger_mark_paid_error($paid), 'cannot mark paid twice');

    $reopen = $pdo->prepare(
        'UPDATE internal_ledger SET status = ?, paid_at = NULL, paid_by_user_id = NULL WHERE id = ? AND store_id = ? AND status = ?'
    );
    $reopen->execute(['open', $entryId, $storeId, 'paid']);
    assert_true($reopen->rowCount() === 1, 'reopen updates exactly one row');

    $check->execute([$entryId]);
    $reopened = $check->fetch();
    assert_same('open', $reopened['status'] ?? null, 'row status is open after reopen');
    assert_true($reopened['paid_at'] === null, 'paid_at cleared after reopen');
    assert_true($reopened['paid_by_user_id'] === null, 'paid_by_user_id cleared after reopen');
    assert_true(abs(ledger_open_total($pdo, $storeId) - ($beforeTotal + 77.77)) < 0.01, 'open total restored after reopen');

    $pdo->rollBack();
    echo "PASS: integration lifecycle rolled back (no test data persisted)\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $failures++;
    echo "FAIL: integration lifecycle — {$e->getMessage()}\n";
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed\n");
    exit(1);
}

echo "\nAll libro-interno paid tests passed.\n";
