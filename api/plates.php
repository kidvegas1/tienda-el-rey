<?php
$user = auth_require();
$method = get_method();
$pdo = db();

if ($method === 'GET') {
    $storeId = resolve_store_filter(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
    $status = $_GET['status'] ?? '';
    $storeSql = store_filter_sql('p.store_id', $storeId);
    $where = 'WHERE 1=1' . $storeSql;
    $params = [];
    if ($storeId) $params[] = $storeId;
    if ($status) { $where .= ' AND p.status = ?'; $params[] = $status; }

    $stmt = $pdo->prepare("SELECT p.*, s.name as store_name FROM plates p LEFT JOIN stores s ON s.id = p.store_id {$where} ORDER BY p.created_at DESC LIMIT 200");
    $stmt->execute($params);

    json_response(['plates' => $stmt->fetchAll(), 'scope' => $storeId ? 'store' : 'all']);
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $storeId = resolve_store_id(!empty($data['store_id']) ? (int)$data['store_id'] : null);
    $act = $data['action'] ?? '';

    if ($act === 'create') {
        validate_required($data, ['client_name', 'service_type']);
        $stmt = $pdo->prepare('INSERT INTO plates (store_id, client_name, phone, vin, service_type, delivery_date, payment, balance, total, agent_name, agent_fee, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$storeId, sanitize($data['client_name']), sanitize($data['phone'] ?? ''), sanitize($data['vin'] ?? ''), sanitize($data['service_type']), $data['delivery_date'] ?? null, (float)($data['payment'] ?? 0), (float)($data['balance'] ?? 0), (float)($data['total'] ?? 0), sanitize($data['agent_name'] ?? ''), (float)($data['agent_fee'] ?? 0), $data['status'] ?? 'pending', sanitize($data['notes'] ?? '')]);
        json_response(['success' => true, 'id' => sql_last_insert_id($pdo, 'plates')], 201);
    }

    if ($act === 'update') {
        validate_required($data, ['id', 'client_name', 'service_type']);
        $stmt = $pdo->prepare('UPDATE plates SET client_name = ?, phone = ?, vin = ?, service_type = ?, delivery_date = ?, payment = ?, balance = ?, total = ?, agent_name = ?, agent_fee = ?, status = ?, notes = ? WHERE id = ? AND store_id = ?');
        $stmt->execute([sanitize($data['client_name']), sanitize($data['phone'] ?? ''), sanitize($data['vin'] ?? ''), sanitize($data['service_type']), $data['delivery_date'] ?? null, (float)($data['payment'] ?? 0), (float)($data['balance'] ?? 0), (float)($data['total'] ?? 0), sanitize($data['agent_name'] ?? ''), (float)($data['agent_fee'] ?? 0), $data['status'] ?? 'pending', sanitize($data['notes'] ?? ''), (int)$data['id'], $storeId]);
        json_response(['success' => true]);
    }

    if ($act === 'delete') {
        validate_required($data, ['id']);
        $pdo->prepare('DELETE FROM plates WHERE id = ? AND store_id = ?')->execute([(int)$data['id'], $storeId]);
        json_response(['success' => true]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
