<?php
$user = auth_require();
if (!auth_is_admin()) {
    json_error('Admin access required', 403);
}
$method = get_method();
$pdo = db();

if ($method === 'GET') {
    $year = (int)($_GET['year'] ?? date('Y'));
    $requested = !empty($_GET['store_id']) ? (int)$_GET['store_id'] : null;
    if ($requested !== null) {
        auth_require_store_access($requested);
    }
    $filterStore = resolve_store_filter($requested);
    $storeWhere = $filterStore ? ' AND t.store_id = ?' : '';
    $storeParam = $filterStore ? [(int)$filterStore] : [];

    $yearSent = sql_year('t.date_sent');
    $monthSent = sql_month('t.date_sent');
    $yearDateSent = sql_year('date_sent');
    $monthDateSent = sql_month('date_sent');
    $yearReportFrom = sql_year('report_date_from');
    $yearTxnDate = sql_year('bt.transaction_date');

    $revenueStmt = $pdo->prepare("SELECT s.name as store_name, s.id as store_id, COUNT(t.id) as transfer_count, COALESCE(SUM(t.amount_usd),0) as total_usd, COALESCE(SUM(t.fee),0) as total_fees FROM stores s LEFT JOIN transfers t ON t.store_id = s.id AND {$yearSent} = ? WHERE " . sql_is_active('s.active') . ($filterStore ? ' AND s.id = ?' : '') . " GROUP BY s.id, s.name ORDER BY total_usd DESC");
    $revenueStmt->execute($filterStore ? [$year, (int)$filterStore] : [$year]);
    $revenue = $revenueStmt->fetchAll();

    $monthlyQ = "SELECT {$monthSent} as month, COUNT(*) as count, COALESCE(SUM(t.amount_usd),0) as total FROM transfers t WHERE {$yearSent} = ?{$storeWhere} GROUP BY {$monthSent} ORDER BY month";
    $monthlyStmt = $pdo->prepare($monthlyQ);
    $monthlyStmt->execute(array_merge([$year], $storeParam));
    $monthly = $monthlyStmt->fetchAll();

    $topQ = "SELECT c.id, c.name, c.phone, c.sender_id_path, COUNT(t.id) as transfer_count, SUM(t.amount_usd) as total_sent, MAX(t.date_sent) as last_transfer FROM clients c JOIN transfers t ON t.client_id = c.id WHERE {$yearSent} = ?{$storeWhere} GROUP BY c.id, c.name, c.phone, c.sender_id_path ORDER BY total_sent DESC LIMIT 50";
    $topStmt = $pdo->prepare($topQ);
    $topStmt->execute(array_merge([$year], $storeParam));
    $topSenders = $topStmt->fetchAll();

    $curMonth = (int)date('m');
    $curYear = (int)date('Y');
    foreach ($topSenders as &$sender) {
        $mStmt = $pdo->prepare("SELECT COALESCE(SUM(amount_usd),0) as m FROM transfers WHERE client_id = ? AND {$yearDateSent} = ? AND {$monthDateSent} = ?");
        $mStmt->execute([$sender['id'], $curYear, $curMonth]);
        $sender['current_month_total'] = (float)$mStmt->fetchColumn();
    }
    unset($sender);

    $companyQ = "SELECT t.company, COUNT(*) as count, COALESCE(SUM(t.amount_usd),0) as total FROM transfers t WHERE {$yearSent} = ? AND t.company IS NOT NULL AND t.company != ''{$storeWhere} GROUP BY t.company ORDER BY total DESC";
    $companyStmt = $pdo->prepare($companyQ);
    $companyStmt->execute(array_merge([$year], $storeParam));
    $companies = $companyStmt->fetchAll();

    $barriQ = "SELECT COUNT(*) as reports_count, COALESCE(SUM(total_principal),0) as barri_principal, COALESCE(SUM(total_agcomm),0) as barri_commission, COALESCE(SUM(total_transactions),0) as barri_txn_count FROM barri_reports WHERE {$yearReportFrom} = ?" . ($filterStore ? ' AND store_id = ?' : '');
    $barriStmt = $pdo->prepare($barriQ);
    $barriStmt->execute($filterStore ? [$year, (int)$filterStore] : [$year]);
    $barriSummary = $barriStmt->fetch();

    $yearsStmt = $pdo->query("SELECT DISTINCT {$yearDateSent} as y FROM transfers ORDER BY y DESC");
    $years = array_column($yearsStmt->fetchAll(), 'y');

    json_response([
        'revenue_by_store' => $revenue,
        'monthly_trend' => $monthly,
        'top_senders' => $topSenders,
        'company_breakdown' => $companies,
        'barri_summary' => $barriSummary,
        'years' => $years,
        'year' => $year,
    ]);
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $act = $data['action'] ?? '';

    if ($act === 'export') {
        $year = (int)($data['year'] ?? date('Y'));
        $requested = !empty($data['store_id']) ? (int)$data['store_id'] : null;
        if ($requested !== null) {
            auth_require_store_access($requested);
        }
        $filterStore = resolve_store_filter($requested);
        $storeWhere = $filterStore ? ' AND t.store_id = ?' : '';
        $params = $filterStore ? [$year, (int)$filterStore] : [$year];

        $yearSent = sql_year('t.date_sent');
        $yearTxnDate = sql_year('bt.transaction_date');

        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tienda-el-rey-registros-' . $year . '.csv"');

        $csv = function($out, $row) { fputcsv($out, $row, ',', '"', '\\'); };
        $out = fopen('php://output', 'w');
        $csv($out, ['Store', 'Date', 'Client', 'Beneficiary', 'Company', 'Reference', 'Type', 'Amount USD', 'Fee', 'Tax', 'Total', 'Source']);

        $q = "SELECT s.name as store_name, t.date_sent, c.name as client_name, t.beneficiary, t.company, t.transaction_code, t.transaction_type, t.amount_usd, t.fee, t.tax, (t.amount_usd + COALESCE(t.fee,0) + COALESCE(t.tax,0)) as total, t.source FROM transfers t JOIN clients c ON c.id = t.client_id JOIN stores s ON s.id = t.store_id WHERE {$yearSent} = ?{$storeWhere} ORDER BY t.date_sent";
        $stmt = $pdo->prepare($q);
        $stmt->execute($params);
        while ($row = $stmt->fetch()) {
            $csv($out, [
                $row['store_name'], $row['date_sent'], $row['client_name'],
                $row['beneficiary'], $row['company'] ?? '', $row['transaction_code'] ?? '',
                $row['transaction_type'] ?? '', $row['amount_usd'], $row['fee'] ?? '',
                $row['tax'] ?? '', $row['total'], $row['source'] ?? 'manual'
            ]);
        }

        $bq = "SELECT s.name as store_name, CONCAT(bt.transaction_date,' ',bt.transaction_time) as date_sent, bt.customer_name, bt.beneficiary_name as beneficiary, COALESCE(br.company,'Barri') as company, bt.reference_number, bt.transaction_type, bt.principal, bt.fee, bt.tax, bt.total FROM barri_transactions bt JOIN stores s ON s.id = bt.store_id LEFT JOIN barri_reports br ON br.id = bt.report_id WHERE " . sql_is_false('bt.pushed_to_transfers') . " AND {$yearTxnDate} = ?" . ($filterStore ? ' AND bt.store_id = ?' : '') . " ORDER BY bt.transaction_date";
        $bStmt = $pdo->prepare($bq);
        $bStmt->execute($params);
        while ($row = $bStmt->fetch()) {
            $csv($out, [
                $row['store_name'], $row['date_sent'], $row['customer_name'],
                $row['beneficiary'], $row['company'], $row['reference_number'],
                $row['transaction_type'], $row['principal'], $row['fee'],
                $row['tax'], $row['total'], 'barri_unpushed'
            ]);
        }

        fclose($out);
        exit;
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
