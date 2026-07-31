<?php
$user = auth_require();
$method = get_method();
$pdo = db();

if ($method === 'GET') {
    $storeId = resolve_store_filter(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
    $category = $_GET['category'] ?? '';
    $storeSql = store_filter_sql('ae.store_id', $storeId);
    $where = 'WHERE 1=1' . $storeSql;
    $params = [];
    if ($storeId) $params[] = $storeId;
    if ($category) {
        $where .= ' AND ae.category = ?';
        $params[] = $category;
    }

    $stmt = $pdo->prepare("SELECT ae.*, s.name as store_name FROM accounting_entries ae LEFT JOIN stores s ON s.id = ae.store_id {$where} ORDER BY ae.entry_date DESC, ae.id DESC LIMIT 200");
    $stmt->execute($params);

    $totalsWhere = 'WHERE 1=1' . store_filter_sql('store_id', $storeId);
    $totalsParams = $storeId ? [$storeId] : [];
    $totals = $pdo->prepare("SELECT entry_type, COALESCE(SUM(amount),0) as total FROM accounting_entries {$totalsWhere} GROUP BY entry_type");
    $totals->execute($totalsParams);
    $sums = ['receivable' => 0, 'payable' => 0];
    foreach ($totals->fetchAll() as $row) $sums[$row['entry_type']] = (float)$row['total'];

    $categories = $pdo->prepare("SELECT DISTINCT category FROM accounting_entries {$totalsWhere} ORDER BY category");
    $categories->execute($totalsParams);

    json_response([
        'entries'       => $stmt->fetchAll(),
        'total_receivable' => $sums['receivable'],
        'total_payable'    => $sums['payable'],
        'net_balance'      => $sums['receivable'] - $sums['payable'],
        'categories'       => array_column($categories->fetchAll(), 'category'),
        'scope'            => $storeId ? 'store' : 'all',
    ]);
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $storeId = resolve_store_id(!empty($data['store_id']) ? (int)$data['store_id'] : null);
    $act = $data['action'] ?? '';

    if ($act === 'create') {
        validate_required($data, ['category', 'description', 'amount', 'entry_type', 'entry_date']);
        $stmt = $pdo->prepare('INSERT INTO accounting_entries (store_id, category, description, amount, entry_type, entry_date, notes) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$storeId, sanitize($data['category']), sanitize($data['description']), (float)$data['amount'], $data['entry_type'], $data['entry_date'], sanitize($data['notes'] ?? '')]);
        json_response(['success' => true, 'id' => sql_last_insert_id($pdo, 'accounting_entries')], 201);
    }

    if ($act === 'update') {
        validate_required($data, ['id', 'category', 'description', 'amount', 'entry_type', 'entry_date']);
        $stmt = $pdo->prepare('UPDATE accounting_entries SET category = ?, description = ?, amount = ?, entry_type = ?, entry_date = ?, notes = ? WHERE id = ? AND store_id = ?');
        $stmt->execute([sanitize($data['category']), sanitize($data['description']), (float)$data['amount'], $data['entry_type'], $data['entry_date'], sanitize($data['notes'] ?? ''), (int)$data['id'], $storeId]);
        json_response(['success' => true]);
    }

    if ($act === 'delete') {
        validate_required($data, ['id']);
        $pdo->prepare('DELETE FROM accounting_entries WHERE id = ? AND store_id = ?')->execute([(int)$data['id'], $storeId]);
        json_response(['success' => true]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
