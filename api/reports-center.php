<?php
/**
 * Reports Center — period KPIs + transaction analysis.
 * Admins: all stores. Managers/employees: their store only.
 */
$user = auth_require();
$method = get_method();
$pdo = db();
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/transaction-analytics.php';

if ($method === 'GET') {
    try {
        $period = $_GET['period'] ?? 'monthly';
        $company = trim((string)($_GET['company'] ?? ''));
        $sender = trim((string)($_GET['sender'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 100;
        $offset = ($page - 1) * $limit;

        // Non-admins are locked to their store; admins may filter optionally
        $storeFilter = resolve_store_filter(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
        $storeId = $storeFilter;

        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;

        if (!$dateFrom || !$dateTo) {
            $now = new DateTime('now');
            switch ($period) {
                case 'daily':
                    $dateFrom = $now->format('Y-m-d');
                    $dateTo = $dateFrom;
                    break;
                case 'weekly':
                    $start = (clone $now)->modify('monday this week');
                    $dateFrom = $start->format('Y-m-d');
                    $dateTo = $now->format('Y-m-d');
                    break;
                case 'monthly':
                    $dateFrom = $now->format('Y-m-01');
                    $dateTo = $now->format('Y-m-t');
                    break;
                case 'annual':
                    $dateFrom = $now->format('Y-01-01');
                    $dateTo = $now->format('Y-12-31');
                    break;
                case 'all':
                    $dateFrom = '2000-01-01';
                    $dateTo = '2099-12-31';
                    break;
                default:
                    $dateFrom = $now->format('Y-m-01');
                    $dateTo = $now->format('Y-m-t');
            }
        }

        $dateFromFull = $dateFrom . ' 00:00:00';
        $dateToFull = $dateTo . ' 23:59:59';

        $where = 't.date_sent BETWEEN ? AND ?';
        $params = [$dateFromFull, $dateToFull];

        if ($company !== '') {
            $where .= ' AND t.company = ?';
            $params[] = $company;
        }
        if ($storeId) {
            $where .= ' AND t.store_id = ?';
            $params[] = $storeId;
        }
        if ($sender !== '') {
            $where .= ' AND c.name LIKE ?';
            $params[] = '%' . $sender . '%';
        }

        // Exclude synthetic Money Order bucket from sender KPIs
        $senderWhere = $where . " AND NOT (LOWER(TRIM(COALESCE(c.name,''))) IN ('money order','giro postal','otros servicios','other services'))";

        $summaryQ = "SELECT
            COUNT(t.id) as total_transactions,
            COALESCE(SUM(t.amount_usd),0) as total_principal,
            COALESCE(SUM(t.fee),0) as total_fees,
            COALESCE(SUM(t.tax),0) as total_tax,
            COALESCE(SUM(t.amount_usd + COALESCE(t.fee,0) + COALESCE(t.tax,0)),0) as total_amount,
            COUNT(DISTINCT t.client_id) as unique_senders,
            COUNT(DISTINCT t.company) as companies_used,
            COALESCE(AVG(NULLIF(t.amount_usd,0)),0) as avg_ticket
            FROM transfers t
            LEFT JOIN clients c ON c.id = t.client_id
            WHERE {$where}";
        $stmt = $pdo->prepare($summaryQ);
        $stmt->execute($params);
        $summary = $stmt->fetch() ?: [];

        $companyQ = "SELECT
            t.company,
            COUNT(*) as count,
            COALESCE(SUM(t.amount_usd),0) as total_principal,
            COALESCE(SUM(t.fee),0) as total_fees,
            COALESCE(SUM(t.tax),0) as total_tax,
            COALESCE(SUM(t.amount_usd + COALESCE(t.fee,0) + COALESCE(t.tax,0)),0) as total_amount,
            COUNT(DISTINCT t.client_id) as unique_senders
            FROM transfers t
            LEFT JOIN clients c ON c.id = t.client_id
            WHERE {$where} AND t.company IS NOT NULL AND t.company != ''
            GROUP BY t.company ORDER BY total_principal DESC";
        $stmt = $pdo->prepare($companyQ);
        $stmt->execute($params);
        $companies = $stmt->fetchAll();

        $typeQ = "SELECT
            COALESCE(NULLIF(TRIM(LOWER(REPLACE(t.transaction_type,' ','_'))), ''), 'unknown') as txn_type,
            COUNT(*) as count,
            COALESCE(SUM(t.amount_usd),0) as total_principal,
            COALESCE(SUM(t.fee),0) as total_fees
            FROM transfers t
            LEFT JOIN clients c ON c.id = t.client_id
            WHERE {$where}
            GROUP BY COALESCE(NULLIF(TRIM(LOWER(REPLACE(t.transaction_type,' ','_'))), ''), 'unknown')
            ORDER BY total_principal DESC";
        $stmt = $pdo->prepare($typeQ);
        $stmt->execute($params);
        $typeBreakdown = $stmt->fetchAll();

        $topQ = "SELECT
            c.id, c.name, c.phone, c.monthly_limit, c.income_verified, c.sender_id_path,
            COUNT(t.id) as transfer_count,
            COALESCE(SUM(t.amount_usd),0) as total_sent,
            COALESCE(SUM(t.fee),0) as total_fees,
            MAX(t.date_sent) as last_transfer
            FROM transfers t
            JOIN clients c ON c.id = t.client_id
            WHERE {$senderWhere}
            GROUP BY c.id, c.name, c.phone, c.monthly_limit, c.income_verified, c.sender_id_path
            ORDER BY total_sent DESC LIMIT 100";
        $stmt = $pdo->prepare($topQ);
        $stmt->execute($params);
        $topSenders = $stmt->fetchAll();

        $fincenLimit = app_setting_float('fincen_global_limit', 3000.0);
        $fincenCount = 0;
        foreach ($topSenders as $s) {
            if ((float)$s['total_sent'] >= $fincenLimit) {
                $fincenCount++;
            }
        }

        $dayExpr = sql_date('t.date_sent');
        $dailyQ = "SELECT
            {$dayExpr} as day,
            COUNT(*) as count,
            COALESCE(SUM(t.amount_usd),0) as total
            FROM transfers t
            LEFT JOIN clients c ON c.id = t.client_id
            WHERE {$where}
            GROUP BY {$dayExpr} ORDER BY day";
        $stmt = $pdo->prepare($dailyQ);
        $stmt->execute($params);
        $dailyBreakdown = $stmt->fetchAll();

        $storeQ = "SELECT
            s.id as store_id, s.name as store_name,
            COUNT(t.id) as count,
            COALESCE(SUM(t.amount_usd),0) as total,
            COALESCE(SUM(t.fee),0) as fees
            FROM transfers t
            JOIN stores s ON s.id = t.store_id
            LEFT JOIN clients c ON c.id = t.client_id
            WHERE {$where}
            GROUP BY s.id, s.name ORDER BY total DESC";
        $stmt = $pdo->prepare($storeQ);
        $stmt->execute($params);
        $storeBreakdown = $stmt->fetchAll();

        $countQ = "SELECT COUNT(*) FROM transfers t LEFT JOIN clients c ON c.id = t.client_id WHERE {$where}";
        $stmt = $pdo->prepare($countQ);
        $stmt->execute($params);
        $totalRows = (int)$stmt->fetchColumn();

        $txnQ = "SELECT
            t.id, t.date_sent, t.amount_usd, t.fee, t.tax,
            (t.amount_usd + COALESCE(t.fee,0) + COALESCE(t.tax,0)) as total,
            t.company, t.transaction_code, t.transaction_type, t.beneficiary, t.source,
            c.name as client_name, c.id as client_id,
            s.name as store_name
            FROM transfers t
            LEFT JOIN clients c ON c.id = t.client_id
            LEFT JOIN stores s ON s.id = t.store_id
            WHERE {$where}
            ORDER BY t.date_sent DESC
            LIMIT ? OFFSET ?";
        $txnParams = array_merge($params, [$limit, $offset]);
        $stmt = $pdo->prepare($txnQ);
        $stmt->execute($txnParams);
        $transactions = $stmt->fetchAll();

        $brWhere = 'br.report_date_from >= ? AND br.report_date_to <= ?';
        $brParams = [$dateFrom, $dateTo];
        if ($storeId) {
            $brWhere .= ' AND br.store_id = ?';
            $brParams[] = $storeId;
        }
        $brQ = "SELECT br.id, br.agency_name, br.agency_number, br.company, br.report_type,
                       br.report_date_from as date_from, br.report_date_to as date_to,
                       br.total_transactions, br.total_principal, br.total_agcomm as total_commission,
                       br.beginning_balance, br.ending_balance, br.total_fees, br.total_tax,
                       br.filename, br.original_name, br.status, br.created_at, s.name as store_name
                FROM barri_reports br
                LEFT JOIN stores s ON s.id = br.store_id
                WHERE {$brWhere}
                ORDER BY br.report_date_from DESC";
        $stmt = $pdo->prepare($brQ);
        $stmt->execute($brParams);
        $barriReports = $stmt->fetchAll();

        // Pattern KPIs (avg ticket, high-frequency, over-limit, multi-store)
        $patterns = txn_analytics_patterns($pdo, $storeId, $dateFrom, $dateTo);

        // Other services (nameless money orders) in period — from barri_transactions
        $moParams = [$dateFrom, $dateTo];
        $moStore = '';
        if ($storeId) {
            $moStore = ' AND bt.store_id = ?';
            $moParams[] = $storeId;
        }
        $moQ = "SELECT COUNT(*) AS count,
                       COALESCE(SUM(bt.principal),0) AS principal,
                       COALESCE(SUM(bt.fee),0) AS fees
                FROM barri_transactions bt
                WHERE REPLACE(LOWER(COALESCE(bt.transaction_type,'')),' ','_') = 'money_order'
                  AND (
                        bt.client_id IS NULL
                     OR LOWER(TRIM(COALESCE(bt.customer_name,''))) IN ('money order','giro postal','')
                     OR bt.customer_name LIKE 'MO %'
                  )
                  AND bt.transaction_date >= ? AND bt.transaction_date <= ?
                  {$moStore}";
        $stmt = $pdo->prepare($moQ);
        $stmt->execute($moParams);
        $otherServices = $stmt->fetch() ?: ['count' => 0, 'principal' => 0, 'fees' => 0];

        $txnCount = (int)($summary['total_transactions'] ?? 0);
        $principal = (float)($summary['total_principal'] ?? 0);
        $kpis = [
            'avg_ticket' => round((float)($summary['avg_ticket'] ?? 0), 2),
            'fee_rate_pct' => $principal > 0
                ? round(((float)($summary['total_fees'] ?? 0) / $principal) * 100, 2)
                : 0,
            'txns_per_sender' => ((int)($summary['unique_senders'] ?? 0)) > 0
                ? round($txnCount / (int)$summary['unique_senders'], 2)
                : 0,
            'fincen_flagged' => $fincenCount,
            'fincen_limit' => $fincenLimit,
            'over_limit_clients' => (int)($patterns['over_limit_clients_count'] ?? 0),
            'high_frequency_days' => count($patterns['high_frequency_clients'] ?? []),
            'multi_store_clients' => (int)($patterns['multi_store_clients_count'] ?? 0),
            'other_services_count' => (int)($otherServices['count'] ?? 0),
            'other_services_principal' => (float)($otherServices['principal'] ?? 0),
            'other_services_fees' => (float)($otherServices['fees'] ?? 0),
        ];

        if (auth_is_admin()) {
            $companiesAvail = $pdo->query("SELECT DISTINCT company FROM transfers WHERE company IS NOT NULL AND company != '' ORDER BY company")->fetchAll(PDO::FETCH_COLUMN);
            $stores = $pdo->query('SELECT id, name FROM stores WHERE ' . sql_is_active() . ' ORDER BY name')->fetchAll();
        } else {
            $companiesAvail = [];
            $cq = $pdo->prepare("SELECT DISTINCT company FROM transfers WHERE company IS NOT NULL AND company != '' AND store_id = ? ORDER BY company");
            $cq->execute([(int)($_SESSION['store_id'] ?? 0)]);
            $companiesAvail = $cq->fetchAll(PDO::FETCH_COLUMN);
            $stores = [];
            $sid = (int)($_SESSION['store_id'] ?? 0);
            if ($sid) {
                $sq = $pdo->prepare('SELECT id, name FROM stores WHERE id = ?');
                $sq->execute([$sid]);
                $stores = $sq->fetchAll();
            }
        }

        json_response([
            'summary' => $summary,
            'kpis' => $kpis,
            'patterns' => $patterns,
            'type_breakdown' => $typeBreakdown,
            'companies' => $companies,
            'top_senders' => $topSenders,
            'fincen_flagged' => $fincenCount,
            'fincen_limit' => $fincenLimit,
            'daily_breakdown' => $dailyBreakdown,
            'store_breakdown' => $storeBreakdown,
            'transactions' => $transactions,
            'barri_reports' => $barriReports,
            'other_services' => $otherServices,
            'total_rows' => $totalRows,
            'page' => $page,
            'pages' => (int)ceil(max(1, $totalRows) / $limit),
            'filters' => [
                'period' => $period,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'company' => $company,
                'store_id' => $storeId,
                'sender' => $sender,
            ],
            'available_companies' => $companiesAvail,
            'available_stores' => $stores,
            'scope' => auth_is_admin() ? 'all' : 'store',
        ]);
    } catch (Throwable $e) {
        error_log('[reports-center] ' . $e->getMessage());
        json_error('Reports Center failed: ' . $e->getMessage(), 500);
    }
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $act = $data['action'] ?? '';

    if ($act === 'export') {
        $dateFrom = ($data['date_from'] ?? date('Y-m-01')) . ' 00:00:00';
        $dateTo = ($data['date_to'] ?? date('Y-m-t')) . ' 23:59:59';
        $company = $data['company'] ?? '';
        if (auth_is_admin()) {
            $storeId = !empty($data['store_id']) ? (int)$data['store_id'] : null;
        } else {
            $storeId = resolve_store_id(!empty($data['store_id']) ? (int)$data['store_id'] : null);
        }
        $sender = $data['sender'] ?? '';

        $where = 't.date_sent BETWEEN ? AND ?';
        $params = [$dateFrom, $dateTo];
        if ($company) { $where .= ' AND t.company = ?'; $params[] = $company; }
        if ($storeId) { $where .= ' AND t.store_id = ?'; $params[] = $storeId; }
        if ($sender) { $where .= ' AND c.name LIKE ?'; $params[] = '%' . $sender . '%'; }

        $filename = 'tienda-el-rey-reporte-' . date('Y-m-d') . '.csv';
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $csv = function($out, $row) { fputcsv($out, $row, ',', '"', '\\'); };
        $out = fopen('php://output', 'w');
        $csv($out, ['Date', 'Store', 'Client', 'Beneficiary', 'Company', 'Reference', 'Type', 'Principal (USD)', 'Fee', 'Tax', 'Total', 'Source']);

        $q = "SELECT t.date_sent, s.name as store_name, c.name as client_name, t.beneficiary, t.company,
                     t.transaction_code, t.transaction_type, t.amount_usd, t.fee, t.tax,
                     (t.amount_usd + COALESCE(t.fee,0) + COALESCE(t.tax,0)) as total, t.source
              FROM transfers t
              LEFT JOIN clients c ON c.id = t.client_id
              LEFT JOIN stores s ON s.id = t.store_id
              WHERE {$where}
              ORDER BY t.date_sent DESC";
        $stmt = $pdo->prepare($q);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $csv($out, $row);
        }
        fclose($out);
        exit;
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
