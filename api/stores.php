<?php
$user = auth_require();
$method = get_method();
$pdo = db();

if ($method === 'GET') {
    auth_require_admin();
    $id = $_GET['id'] ?? null;
    if ($id) {
        $stmt = $pdo->prepare('SELECT * FROM stores WHERE id = ?');
        $stmt->execute([(int)$id]);
        $store = $stmt->fetch();
        if (!$store) json_error('Store not found', 404);
        json_response(['store' => $store]);
    }
    $includeInactive = isset($_GET['include_inactive']) && (string)$_GET['include_inactive'] !== '0';
    if ($includeInactive) {
        $stores = $pdo->query('SELECT * FROM stores ORDER BY active DESC, name')->fetchAll();
    } else {
        $stores = $pdo->query('SELECT * FROM stores WHERE ' . sql_is_active() . ' ORDER BY name')->fetchAll();
    }
    json_response(['stores' => $stores]);
}

if ($method === 'POST') {
    auth_require_admin();
    csrf_verify();
    $data = get_json_body();
    $act = $data['action'] ?? 'create';

    if ($act === 'create') {
        validate_required($data, ['name']);
        $publishInventory = !array_key_exists('publish_inventory', $data) || filter_var($data['publish_inventory'], FILTER_VALIDATE_BOOLEAN) || $data['publish_inventory'] === 1 || $data['publish_inventory'] === '1';
        $publishPrices = array_key_exists('publish_prices', $data) && (filter_var($data['publish_prices'], FILTER_VALIDATE_BOOLEAN) || $data['publish_prices'] === 1 || $data['publish_prices'] === '1');
        $stmt = $pdo->prepare('INSERT INTO stores (name, address, phone, barri_agency_number, barri_operator_number, viamericas_agency_number, intercambio_agency_number, intermex_agency_number, publish_inventory, publish_prices) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            sanitize($data['name']),
            sanitize($data['address'] ?? ''),
            sanitize($data['phone'] ?? ''),
            sanitize($data['barri_agency_number'] ?? ''),
            sanitize($data['barri_operator_number'] ?? ''),
            sanitize($data['viamericas_agency_number'] ?? ''),
            sanitize($data['intercambio_agency_number'] ?? ''),
            sanitize($data['intermex_agency_number'] ?? ''),
            $publishInventory ? 1 : 0,
            $publishPrices ? 1 : 0,
        ]);
        json_response(['success' => true, 'id' => sql_last_insert_id($pdo, 'stores')], 201);
    }

    if ($act === 'update') {
        validate_required($data, ['id', 'name']);
        $publishInventory = !array_key_exists('publish_inventory', $data) || filter_var($data['publish_inventory'], FILTER_VALIDATE_BOOLEAN) || $data['publish_inventory'] === 1 || $data['publish_inventory'] === '1';
        $publishPrices = array_key_exists('publish_prices', $data) && (filter_var($data['publish_prices'], FILTER_VALIDATE_BOOLEAN) || $data['publish_prices'] === 1 || $data['publish_prices'] === '1');
        $stmt = $pdo->prepare('UPDATE stores SET name = ?, address = ?, phone = ?, barri_agency_number = ?, barri_operator_number = ?, viamericas_agency_number = ?, intercambio_agency_number = ?, intermex_agency_number = ?, publish_inventory = ?, publish_prices = ? WHERE id = ?');
        $stmt->execute([
            sanitize($data['name']),
            sanitize($data['address'] ?? ''),
            sanitize($data['phone'] ?? ''),
            sanitize($data['barri_agency_number'] ?? ''),
            sanitize($data['barri_operator_number'] ?? ''),
            sanitize($data['viamericas_agency_number'] ?? ''),
            sanitize($data['intercambio_agency_number'] ?? ''),
            sanitize($data['intermex_agency_number'] ?? ''),
            $publishInventory ? 1 : 0,
            $publishPrices ? 1 : 0,
            (int)$data['id'],
        ]);
        json_response(['success' => true]);
    }

    if ($act === 'deactivate') {
        validate_required($data, ['id']);
        $id = (int)$data['id'];
        ensure_store_exists($pdo, $id);
        $pdo->prepare('UPDATE stores SET active = ' . sql_bool(false) . ' WHERE id = ?')->execute([$id]);
        $pdo->prepare('UPDATE users SET store_id = NULL WHERE store_id = ? AND role <> ?')->execute([$id, 'admin']);
        json_response(['success' => true, 'mode' => 'deactivated']);
    }

    if ($act === 'reactivate') {
        validate_required($data, ['id']);
        $id = (int)$data['id'];
        ensure_store_exists($pdo, $id);
        $pdo->prepare('UPDATE stores SET active = ' . sql_bool(true) . ' WHERE id = ?')->execute([$id]);
        json_response(['success' => true, 'mode' => 'reactivated']);
    }

    if ($act === 'delete') {
        validate_required($data, ['id']);
        $id = (int)$data['id'];
        ensure_store_exists($pdo, $id);

        $activeCount = (int)$pdo->query(
            'SELECT COUNT(*) FROM stores WHERE ' . sql_is_active()
        )->fetchColumn();
        $stmtActive = $pdo->prepare('SELECT active FROM stores WHERE id = ?');
        $stmtActive->execute([$id]);
        $isActive = (bool)$stmtActive->fetchColumn();

        if ($isActive && $activeCount <= 1) {
            json_error('Cannot delete the last active store. Create another store first.', 400);
        }

        // Free nullable user links before attempting hard delete.
        $pdo->prepare('UPDATE users SET store_id = NULL WHERE store_id = ?')->execute([$id]);

        $related = store_related_counts($pdo, $id);
        $hasHistory = array_sum($related) > 0;

        if ($hasHistory && empty($data['force'])) {
            // Soft-delete when historical data would block removal.
            $pdo->prepare('UPDATE stores SET active = ' . sql_bool(false) . ' WHERE id = ?')->execute([$id]);
            json_response([
                'success' => true,
                'mode' => 'deactivated',
                'reason' => 'has_related_records',
                'related' => $related,
            ]);
        }

        try {
            $pdo->prepare('DELETE FROM stores WHERE id = ?')->execute([$id]);
            json_response(['success' => true, 'mode' => 'deleted']);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE stores SET active = ' . sql_bool(false) . ' WHERE id = ?')->execute([$id]);
            json_response([
                'success' => true,
                'mode' => 'deactivated',
                'reason' => 'foreign_key_blocked',
            ]);
        }
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);

function ensure_store_exists(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('SELECT id FROM stores WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        json_error('Store not found', 404);
    }
}

function store_related_counts(PDO $pdo, int $id): array {
    $tables = [
        'caja_sessions',
        'transfers',
        'client_activity_log',
        'transfer_security_alerts',
        'internal_ledger',
        'employees',
        'clock_ins',
        'schedules',
        'transfer_statistics',
        'accounting_entries',
        'receipts',
        'inventory',
        'events',
        'plates',
        'secure_notes',
        'excel_imports',
        'barri_reports',
        'barri_transactions',
    ];
    $counts = [];
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE store_id = ?");
            $stmt->execute([$id]);
            $count = (int)$stmt->fetchColumn();
            if ($count > 0) {
                $counts[$table] = $count;
            }
        } catch (Throwable $e) {
            // Table may not exist in every environment; ignore.
        }
    }
    return $counts;
}
