<?php
$user = auth_require_admin();
$method = get_method();
$pdo = db();
require_once __DIR__ . '/../includes/reconciliation.php';

function recon_parse_dates(): array {
    $dateFrom = $_GET['date_from'] ?? $_POST['date_from'] ?? date('Y-m-01');
    $dateTo = $_GET['date_to'] ?? $_POST['date_to'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        json_error('Invalid date range');
    }
    if ($dateFrom > $dateTo) {
        json_error('date_from must be before date_to');
    }
    return [$dateFrom, $dateTo];
}

function recon_scope_all(): bool {
    $scope = $_GET['scope'] ?? $_POST['scope'] ?? 'all';
    return $scope !== 'store';
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'summary';
    [$dateFrom, $dateTo] = recon_parse_dates();
    $allStores = recon_scope_all();

    if ($action === 'summary') {
        if ($allStores) {
            $overview = recon_all_stores_overview($pdo, $dateFrom, $dateTo);
            $totalCents = round(array_sum(array_column($overview['summary'], 'cents_lost')), 2);
            json_response([
                'scope'        => 'all',
                'date_from'    => $dateFrom,
                'date_to'      => $dateTo,
                'summary'      => $overview['summary'],
                'store_totals' => $overview['store_totals'],
                'reports'      => $overview['reports'],
                'total_cents'  => $totalCents,
            ]);
        }

        $storeId = resolve_store_id(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
        $summary = recon_summary_with_cents($pdo, $storeId, $dateFrom, $dateTo);
        $totalCents = round(array_sum(array_column($summary, 'cents_lost')), 2);
        json_response([
            'scope'       => 'store',
            'store_id'    => $storeId,
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
            'summary'     => $summary,
            'reports'     => recon_imported_reports($pdo, $dateFrom, $dateTo),
            'total_cents' => $totalCents,
        ]);
    }

    if ($action === 'detail') {
        $storeId = resolve_store_id(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
        $company = !empty($_GET['company']) ? sanitize($_GET['company']) : null;
        $rows = recon_cambio_detail($pdo, $storeId, $dateFrom, $dateTo, $company);
        json_response([
            'store_id'  => $storeId,
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            'company'   => $company,
            'rows'      => $rows,
            'count'     => count($rows),
        ]);
    }

    if ($action === 'variances') {
        $status = $_GET['status'] ?? 'open';
        if (!in_array($status, ['open', 'reviewed', 'all'], true)) {
            json_error('Invalid status');
        }
        $storeId = $allStores ? null : resolve_store_id(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
        $variances = recon_list_variances($pdo, $storeId, $status, 200, $dateFrom, $dateTo);
        json_response([
            'scope'     => $allStores ? 'all' : 'store',
            'store_id'  => $storeId,
            'status'    => $status,
            'variances' => $variances,
        ]);
    }

    json_error('Unknown action');
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $act = $data['action'] ?? '';

    if ($act === 'post_cents') {
        validate_required($data, ['store_id', 'company', 'period_month', 'date_from', 'date_to']);
        $storeId = (int)$data['store_id'];
        $result = recon_post_cents_to_ledger(
            $pdo,
            $storeId,
            sanitize($data['company']),
            sanitize($data['period_month']),
            $data['date_from'],
            $data['date_to']
        );
        if (empty($result['success'])) {
            json_error($result['error'] ?? 'Failed to post cents');
        }
        json_response($result);
    }

    if ($act === 'dismiss_variance') {
        validate_required($data, ['id']);
        $varianceId = (int)$data['id'];
        $row = $pdo->prepare('SELECT store_id FROM reconciliation_variances WHERE id = ?');
        $row->execute([$varianceId]);
        $varRow = $row->fetch();
        if (!$varRow) {
            json_error('Variance not found', 404);
        }
        $ok = recon_dismiss_variance($pdo, $varianceId, (int)$varRow['store_id'], (int)$user['id']);
        if (!$ok) {
            json_error('Variance not found or already reviewed', 404);
        }
        json_response(['success' => true]);
    }

    if ($act === 'refresh_variances') {
        $storeId = resolve_store_id(!empty($data['store_id']) ? (int)$data['store_id'] : null);
        $sessionDate = $data['session_date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)) {
            json_error('Invalid session_date');
        }
        $variances = recon_compare_caja_to_reports($pdo, $storeId, $sessionDate, !empty($data['import_id']) ? (int)$data['import_id'] : null);
        json_response(['success' => true, 'variances' => $variances, 'count' => count($variances)]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
