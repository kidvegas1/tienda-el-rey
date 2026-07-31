<?php

$user = auth_require();
$method = get_method();
$pdo = db();

require_once __DIR__ . '/../includes/transfer-security.php';
require_once __DIR__ . '/../includes/client-activity.php';

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'summary') {
        $counts = transfer_security_open_count_by_severity($pdo);
        $totalOpen = array_sum($counts);
        json_response([
            'open_total'    => $totalOpen,
            'by_severity'   => $counts,
        ]);
    }

    $status = $_GET['status'] ?? 'open';
    if (!in_array($status, ['open', 'resolved', 'dismissed', 'all'], true)) {
        $status = 'open';
    }
    $storeId = !empty($_GET['store_id']) ? (int)$_GET['store_id'] : null;
    if ($storeId !== null) {
        auth_require_store_access($storeId);
    }

    $storeFilter = resolve_store_filter($storeId);
    $alerts = transfer_security_list($pdo, $status, $storeFilter);

    json_response(['alerts' => $alerts, 'status_filter' => $status]);
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $act = $data['action'] ?? '';

    if ($act === 'scan') {
        $clientId = !empty($data['client_id']) ? (int)$data['client_id'] : null;
        $days = max(1, min(90, (int)($data['days'] ?? 7)));
        $cap = 200;

        $dateFrom = date('Y-m-d', strtotime("-{$days} days"));
        $where = 'date_sent >= ?';
        $params = [$dateFrom];
        if ($clientId !== null) {
            $where .= ' AND client_id = ?';
            $params[] = $clientId;
        }

        $stmt = $pdo->prepare("SELECT id FROM transfers WHERE {$where} ORDER BY date_sent DESC LIMIT {$cap}");
        $stmt->execute($params);
        $transferIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $scanned = 0;
        $alertsCreated = 0;
        foreach ($transferIds as $tid) {
            $alerts = transfer_security_scan_transfer($pdo, (int)$tid);
            $alertsCreated += count($alerts);
            $scanned++;
        }

        json_response([
            'success'        => true,
            'scanned'        => $scanned,
            'alerts_created' => $alertsCreated,
        ]);
    }

    if ($act === 'resolve') {
        validate_required($data, ['alert_id', 'status']);
        $alertId = (int)$data['alert_id'];
        $newStatus = $data['status'];
        if (!in_array($newStatus, ['resolved', 'dismissed'], true)) {
            json_error('status must be resolved or dismissed', 400);
        }

        $isAllowed = auth_is_admin() || (($_SESSION['user_role'] ?? '') === 'manager');
        if (!$isAllowed) {
            json_error('Admin or manager access required', 403);
        }

        $stmt = $pdo->prepare('SELECT id, client_id FROM transfer_security_alerts WHERE id = ?');
        $stmt->execute([$alertId]);
        $alert = $stmt->fetch();
        if (!$alert) {
            json_error('Alert not found', 404);
        }

        transfer_security_resolve(
            $pdo,
            $alertId,
            (int)$user['id'],
            $newStatus,
            sanitize($data['notes'] ?? '')
        );

        if ((int)($alert['client_id'] ?? 0) > 0) {
            client_activity_log(
                $pdo,
                (int)$alert['client_id'],
                'security_alert',
                "Alert #{$alertId} marked {$newStatus}",
                (int)$user['id']
            );
        }

        json_response(['success' => true]);
    }

    if ($act === 'scan_transfer') {
        validate_required($data, ['transfer_id']);
        $transferId = (int)$data['transfer_id'];

        $stmt = $pdo->prepare('SELECT id FROM transfers WHERE id = ?');
        $stmt->execute([$transferId]);
        if (!$stmt->fetch()) json_error('Transfer not found', 404);

        $alerts = transfer_security_scan_transfer($pdo, $transferId);
        json_response(['success' => true, 'alerts' => $alerts]);
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
