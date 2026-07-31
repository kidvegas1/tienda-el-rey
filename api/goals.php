<?php
/**
 * Store goals (Metas) — admin-only monthly targets for envíos and cambio de cheques.
 */
$user = auth_require();
if (!auth_is_admin()) {
    json_error('Admin access required', 403);
}

require_once __DIR__ . '/../includes/goals.php';

$method = get_method();
$pdo = db();

if ($method === 'GET') {
    $storeId = !empty($_GET['store_id']) ? (int)$_GET['store_id'] : resolve_store_id(null);
    if ($storeId <= 0) {
        json_error('Store is required', 400);
    }

    $year = (int)($_GET['year'] ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));

    json_response([
        'store_id' => $storeId,
        'period_year' => $year,
        'period_month' => $month,
        'metrics' => goals_list_with_progress($pdo, $storeId, $year, $month),
    ]);
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $action = $data['action'] ?? '';

    if ($action === 'upsert') {
        $storeId = (int)($data['store_id'] ?? 0);
        $metricType = (string)($data['metric_type'] ?? '');
        $year = (int)($data['period_year'] ?? date('Y'));
        $month = (int)($data['period_month'] ?? date('n'));
        $targetValue = (float)($data['target_value'] ?? 0);
        $notes = isset($data['notes']) && $data['notes'] !== '' ? (string)$data['notes'] : null;

        if ($storeId <= 0) {
            json_error('Store is required', 400);
        }

        try {
            $id = goals_upsert($pdo, $storeId, $metricType, $year, $month, $targetValue, $notes, (int)$user['id']);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage(), 400);
        }

        json_response([
            'ok' => true,
            'id' => $id,
            'metrics' => goals_list_with_progress($pdo, $storeId, $year, $month),
        ]);
    }

    if ($action === 'delete') {
        $goalId = (int)($data['id'] ?? 0);
        if ($goalId <= 0) {
            json_error('Goal id is required', 400);
        }

        if (!goals_delete($pdo, $goalId)) {
            json_error('Goal not found', 404);
        }

        json_response(['ok' => true]);
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
