<?php
/**
 * Sales log — list inventory sales; refund (manager+) or erase (admin).
 * Refund/erase restore product quantity automatically.
 */
$user = auth_require();
$method = get_method();
$pdo = db();

if (!auth_is_admin() && !auth_is_manager()) {
    json_error('Managers or admins only', 403);
}

function sales_log_has_status(PDO $pdo): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = 'inventory_movements' AND column_name = 'status'"
            );
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'inventory_movements' AND column_name = 'status'"
            );
            $stmt->execute();
        }
        $cached = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

function sales_log_fetch(PDO $pdo, int $id, ?int $storeId): ?array {
    $sql = 'SELECT im.*, i.product_name, i.quantity AS current_stock, u.name AS cashier_name
            FROM inventory_movements im
            LEFT JOIN inventory i ON i.id = im.inventory_id
            LEFT JOIN users u ON u.id = im.user_id
            WHERE im.id = ? AND im.movement_type = ?'
        . store_filter_sql('im.store_id', $storeId);
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$id, 'sale'], $storeId ? [$storeId] : []));
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Units sold on a sale row (quantity stored negative). */
function sales_log_units(array $sale): int {
    return abs((int)($sale['quantity'] ?? 0));
}

if ($method === 'GET') {
    $storeId = resolve_store_filter(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
    if (auth_is_manager() && !auth_is_admin()) {
        $storeId = (int)($user['store_id'] ?? 0) ?: $storeId;
    }
    $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
    $dateTo = $_GET['date_to'] ?? date('Y-m-d');
    $status = $_GET['status'] ?? 'all'; // all|active|refunded|voided
    $hasStatus = sales_log_has_status($pdo);

    $sql = 'SELECT im.*, i.product_name, u.name AS cashier_name
            FROM inventory_movements im
            LEFT JOIN inventory i ON i.id = im.inventory_id
            LEFT JOIN users u ON u.id = im.user_id
            WHERE im.movement_type = ?
              AND im.created_at >= ? AND im.created_at <= ?'
        . store_filter_sql('im.store_id', $storeId);
    $params = ['sale', $dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
    if ($storeId) $params[] = $storeId;

    if ($hasStatus && $status !== 'all' && in_array($status, ['active', 'refunded', 'voided'], true)) {
        $sql .= ' AND im.status = ?';
        $params[] = $status;
    } elseif ($hasStatus && $status === 'all') {
        // hide erased by default in "all" — show active + refunded
        $sql .= " AND im.status IN ('active','refunded')";
    }

    $sql .= ' ORDER BY im.created_at DESC, im.id DESC LIMIT 300';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $row['units'] = sales_log_units($row);
        $row['status'] = $row['status'] ?? 'active';
    }
    unset($row);

    json_response([
        'sales' => $rows,
        'can_erase' => auth_is_admin(),
        'can_refund' => auth_is_admin() || auth_is_manager(),
        'has_status' => $hasStatus,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ]);
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $action = (string)($data['action'] ?? '');
    if (!sales_log_has_status($pdo)) {
        json_error('Sales log migration not applied. Run migrate-sales-log.sql', 503);
    }

    $storeId = resolve_store_filter(!empty($data['store_id']) ? (int)$data['store_id'] : null);
    if (auth_is_manager() && !auth_is_admin()) {
        $storeId = (int)($user['store_id'] ?? 0) ?: null;
    }

    if ($action === 'refund') {
        validate_required($data, ['id']);
        $sale = sales_log_fetch($pdo, (int)$data['id'], $storeId);
        if (!$sale) json_error('Sale not found', 404);
        if (($sale['status'] ?? 'active') !== 'active') {
            json_error('Sale is already ' . ($sale['status'] ?? 'closed'), 409);
        }
        auth_require_store_access((int)$sale['store_id']);

        $units = sales_log_units($sale);
        if ($units < 1) json_error('Invalid sale quantity', 422);

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE inventory SET quantity = quantity + ? WHERE id = ? AND store_id = ?'
            )->execute([$units, (int)$sale['inventory_id'], (int)$sale['store_id']]);

            $note = trim((string)($sale['notes'] ?? ''));
            $note = ($note !== '' ? $note . "\n" : '') . '[refund] ' . date('c') . ' by #' . (int)$user['id'];
            $upd = $pdo->prepare(
                'UPDATE inventory_movements
                 SET status = ?, refunded_at = ' . sql_now() . ', refunded_by_user_id = ?, notes = ?
                 WHERE id = ? AND status = ?'
            );
            $upd->execute(['refunded', (int)$user['id'], $note, (int)$sale['id'], 'active']);
            if ($upd->rowCount() < 1) {
                $pdo->rollBack();
                json_error('Could not refund sale (already changed)', 409);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('sales-log refund: ' . $e->getMessage());
            json_error('Refund failed', 500);
        }
        json_response(['success' => true, 'status' => 'refunded', 'units_restored' => $units]);
    }

    if ($action === 'erase') {
        if (!auth_is_admin()) {
            json_error('Only admins can erase sales. Managers may refund.', 403);
        }
        validate_required($data, ['id']);
        $sale = sales_log_fetch($pdo, (int)$data['id'], $storeId);
        if (!$sale) json_error('Sale not found', 404);
        auth_require_store_access((int)$sale['store_id']);

        $status = $sale['status'] ?? 'active';
        $units = sales_log_units($sale);

        $pdo->beginTransaction();
        try {
            // Restore stock only if sale still active (refund already restored)
            if ($status === 'active' && $units > 0) {
                $pdo->prepare(
                    'UPDATE inventory SET quantity = quantity + ? WHERE id = ? AND store_id = ?'
                )->execute([$units, (int)$sale['inventory_id'], (int)$sale['store_id']]);
            }
            $pdo->prepare(
                'UPDATE inventory_movements
                 SET status = ?, voided_at = ' . sql_now() . ', voided_by_user_id = ?
                 WHERE id = ?'
            )->execute(['voided', (int)$user['id'], (int)$sale['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('sales-log erase: ' . $e->getMessage());
            json_error('Erase failed', 500);
        }
        json_response([
            'success' => true,
            'status' => 'voided',
            'units_restored' => $status === 'active' ? $units : 0,
        ]);
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
