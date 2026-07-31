<?php
$user = auth_require();
$method = get_method();
$pdo = db();

if ($method === 'GET') {
    if (auth_is_admin()) {
        $storeId = !empty($_GET['store_id']) ? (int)$_GET['store_id'] : null;
    } else {
        $storeId = resolve_store_id(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
    }

    $where = '';
    $params = [];
    if ($storeId) {
        $where = 'AND br.store_id = ?';
        $params[] = $storeId;
    }

    // Latest report per store per company (most recent ending balance)
    $sql = "SELECT
                br.store_id,
                s.name AS store_name,
                COALESCE(br.company, 'Barri') AS company,
                br.ending_balance,
                br.beginning_balance,
                br.report_date_from,
                br.report_date_to,
                br.total_principal,
                br.total_fees,
                br.total_tax,
                br.total_amount,
                br.total_agcomm,
                br.total_transactions,
                br.id AS report_id
            FROM barri_reports br
            JOIN stores s ON s.id = br.store_id
            INNER JOIN (
                SELECT store_id, COALESCE(company, 'Barri') AS company, MAX(report_date_to) AS max_date
                FROM barri_reports
                GROUP BY store_id, COALESCE(company, 'Barri')
            ) latest ON br.store_id = latest.store_id
                    AND COALESCE(br.company, 'Barri') = latest.company
                    AND br.report_date_to = latest.max_date
            WHERE " . sql_is_active('s.active') . " $where
            ORDER BY s.name, br.company";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Aggregate per store
    $storeMap = [];
    foreach ($rows as $r) {
        $sid = (int)$r['store_id'];
        if (!isset($storeMap[$sid])) {
            $storeMap[$sid] = [
                'store_id' => $sid,
                'store_name' => $r['store_name'],
                'companies' => [],
                'total_balance' => 0,
            ];
        }
        $bal = (float)$r['ending_balance'];
        $storeMap[$sid]['companies'][] = [
            'company' => $r['company'],
            'ending_balance' => $bal,
            'beginning_balance' => (float)$r['beginning_balance'],
            'total_principal' => (float)$r['total_principal'],
            'total_fees' => (float)$r['total_fees'],
            'total_tax' => (float)$r['total_tax'],
            'total_amount' => (float)$r['total_amount'],
            'total_commission' => (float)$r['total_agcomm'],
            'total_transactions' => (int)$r['total_transactions'],
            'report_date_from' => $r['report_date_from'],
            'report_date_to' => $r['report_date_to'],
            'report_id' => (int)$r['report_id'],
        ];
        $storeMap[$sid]['total_balance'] += $bal;
    }

    // Summary totals across all stores
    $summaryStmt = $pdo->prepare("SELECT
        COALESCE(br.company, 'Barri') AS company,
        COUNT(*) AS report_count,
        SUM(br.total_principal) AS total_principal,
        SUM(br.total_fees) AS total_fees,
        SUM(br.total_amount) AS total_amount,
        SUM(br.total_agcomm) AS total_commission,
        SUM(br.total_transactions) AS total_transactions
        FROM barri_reports br
        JOIN stores s ON s.id = br.store_id
        WHERE " . sql_is_active('s.active') . " $where
        GROUP BY COALESCE(br.company, 'Barri')
        ORDER BY total_principal DESC");
    $summaryStmt->execute($params);
    $companySummary = $summaryStmt->fetchAll();

    json_response([
        'stores' => array_values($storeMap),
        'company_summary' => $companySummary,
    ]);
}

json_error('Method not allowed', 405);
