<?php

$user = auth_require_admin();
$method = get_method();
$pdo = db();

require_once __DIR__ . '/../includes/transaction-analytics.php';
require_once __DIR__ . '/../includes/transfer-security.php';

if ($method === 'GET') {
    $dateTo   = $_GET['date_to']   ?? date('Y-m-d');
    $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days'));
    $storeId  = !empty($_GET['store_id']) ? (int)$_GET['store_id'] : null;
    if ($storeId !== null) {
        $storeId = resolve_store_id($storeId);
    }
    $year = !empty($_GET['year']) ? (int)$_GET['year'] : (int)substr($dateFrom, 0, 4);

    $summary   = txn_analytics_summary($pdo, $storeId, $dateFrom, $dateTo);
    $byCompany = txn_analytics_by_company($pdo, $storeId, $dateFrom, $dateTo);
    $byMonth   = txn_analytics_by_month($pdo, $storeId, $year);
    $patterns  = txn_analytics_patterns($pdo, $storeId, $dateFrom, $dateTo);

    json_response([
        'summary'           => $summary,
        'by_company'        => $byCompany,
        'by_month'          => $byMonth,
        'patterns'          => $patterns,
        'date_from'         => $dateFrom,
        'date_to'           => $dateTo,
        'year'              => $year,
        'open_alert_counts' => transfer_security_open_count_by_severity($pdo),
    ]);
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $act = $data['action'] ?? 'export';

    if ($act === 'export') {
        $dateTo   = $data['date_to']   ?? date('Y-m-d');
        $dateFrom = $data['date_from'] ?? date('Y-m-d', strtotime('-90 days'));
        $storeId  = !empty($data['store_id']) ? resolve_store_id((int)$data['store_id']) : null;

        $summary   = txn_analytics_summary($pdo, $storeId, $dateFrom, $dateTo);
        $byCompany = txn_analytics_by_company($pdo, $storeId, $dateFrom, $dateTo);

        $csv = "Company,Transfers,Principal,Fees,Unique Clients\n";
        foreach ($byCompany as $row) {
            $csv .= implode(',', [
                '"' . str_replace('"', '""', (string)($row['company'] ?? '')) . '"',
                (int)($row['count'] ?? 0),
                round((float)($row['principal'] ?? 0), 2),
                round((float)($row['fees'] ?? 0), 2),
                (int)($row['unique_clients'] ?? 0),
            ]) . "\n";
        }
        $csv .= "\nSummary\n";
        $csv .= 'Total Transfers,' . (int)($summary['count'] ?? 0) . "\n";
        $csv .= 'Principal,' . round((float)($summary['principal'] ?? 0), 2) . "\n";
        $csv .= 'Fees,' . round((float)($summary['fees'] ?? 0), 2) . "\n";
        $csv .= 'Unique Clients,' . (int)($summary['unique_clients'] ?? 0) . "\n";
        $csv .= "Period,{$dateFrom} to {$dateTo}\n";

        json_response([
            'success'  => true,
            'csv'      => $csv,
            'filename' => "transaction_analysis_{$dateFrom}_to_{$dateTo}.csv",
            'summary'  => $summary,
        ]);
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
