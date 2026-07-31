<?php
/**
 * TDD regression: Caja store scoping, open-session guards, company flag helpers.
 * Run: php scripts/test-caja-update.php
 */
declare(strict_types=1);

require __DIR__ . '/../includes/company-flags.php';

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
    assert_true($expected === $actual, $label . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

echo "== Unit: company flag normalization ==\n";

assert_same('BARRI', company_flag_normalize_key('  barri '), 'trim and uppercase');
assert_same('JP CHEQUES', company_flag_normalize_key('jp   cheques'), 'collapse whitespace');
assert_same('', company_flag_normalize_key('   '), 'empty label normalizes to empty');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} unit test(s) failed\n");
    exit(1);
}

echo "All unit tests passed.\n\n";

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();

function assert_ok(PDO $pdo, string $label, string $sql, array $params = []): void {
    global $failures;
    try {
        $pdo->prepare($sql)->execute($params);
        echo "PASS: $label\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL: $label — {$e->getMessage()}\n";
    }
}

echo "== Integration: Caja SQL guards ==\n";

$storeId = (int)$pdo->query('SELECT id FROM stores ORDER BY id LIMIT 1')->fetchColumn();
if ($storeId <= 0) {
    $failures++;
    echo "FAIL: need at least one store for integration test\n";
    exit(1);
}

assert_ok(
    $pdo,
    'session list scoped to store',
    'SELECT cs.id FROM caja_sessions cs WHERE cs.store_id = ? ORDER BY cs.id DESC LIMIT 1',
    [$storeId]
);

assert_ok(
    $pdo,
    'denom update requires open session subquery',
    'UPDATE caja_denominations SET count = ? WHERE id = ? AND session_id IN (SELECT id FROM caja_sessions WHERE store_id = ? AND status = ?)',
    [0, 0, $storeId, 'open']
);

assert_ok(
    $pdo,
    'entry update requires open session subquery',
    'UPDATE caja_entries SET cash_in = ?, checks_debits = ?, company = ?, notes = ? WHERE id = ? AND session_id IN (SELECT id FROM caja_sessions WHERE store_id = ? AND status = ?)',
    [0, 0, 'TEST', '', 0, $storeId, 'open']
);

if (!company_flags_table_exists($pdo)) {
    echo "SKIP: company_flags table not migrated yet (run migrate-company-flags.sql)\n";
} else {
    echo "\n== Integration: company_flags lifecycle ==\n";

    $userId = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    if ($userId <= 0) {
        $failures++;
        echo "FAIL: need at least one user for company flag integration test\n";
    } else {
        $pdo->beginTransaction();
        try {
            $marker = 'TDD-CAJA-FLAG-' . bin2hex(random_bytes(3));
            $flag = company_flag_set($pdo, $marker, 'Returned check test', $userId);
            assert_true(($flag['company_key'] ?? '') === company_flag_normalize_key($marker), 'flag created with normalized key');

            $map = company_flags_map_for_labels($pdo, [$marker, 'OTHER']);
            assert_true(isset($map[company_flag_normalize_key($marker)]), 'map includes flagged company');

            company_flag_clear($pdo, $marker, $userId);
            assert_true(company_flag_get_active($pdo, company_flag_normalize_key($marker)) === null, 'flag cleared');

            $pdo->rollBack();
            echo "PASS: company flag lifecycle rolled back\n";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $failures++;
            echo "FAIL: company flag lifecycle — {$e->getMessage()}\n";
        }
    }
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed\n");
    exit(1);
}

echo "\nAll caja update tests passed.\n";
