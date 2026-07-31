<?php
$user = auth_require();
$method = get_method();
$pdo = db();

if ($method === 'GET') {
    $storeId = resolve_store_filter(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
    $year = (int)($_GET['year'] ?? date('Y'));

    $storeSql = store_filter_sql('store_id', $storeId);
    $params = [];
    if ($storeId) $params[] = $storeId;

    $stmt = $pdo->prepare('SELECT company, month, year, transfer_count, total_usd FROM transfer_statistics WHERE 1=1' . $storeSql . ' AND year = ? ORDER BY month, company');
    $stmt->execute(array_merge($params, [$year]));
    $stats = $stmt->fetchAll();

    $monthCol = sql_month('date_sent');
    $yearCol = sql_year('date_sent');

    $live = $pdo->prepare("SELECT company, {$monthCol} as month, COUNT(*) as transfer_count, COALESCE(SUM(amount_usd),0) as total_usd FROM transfers WHERE 1=1{$storeSql} AND {$yearCol} = ? AND company IS NOT NULL AND company != '' GROUP BY company, {$monthCol} ORDER BY month, company");
    $live->execute(array_merge($params, [$year]));
    $liveStats = $live->fetchAll();

    $totals = $pdo->prepare("SELECT company, COUNT(*) as transfer_count, COALESCE(SUM(amount_usd),0) as total_usd FROM transfers WHERE 1=1{$storeSql} AND {$yearCol} = ? AND company IS NOT NULL AND company != '' GROUP BY company ORDER BY total_usd DESC");
    $totals->execute(array_merge($params, [$year]));

    $monthly = $pdo->prepare("SELECT {$monthCol} as month, COUNT(*) as transfer_count, COALESCE(SUM(amount_usd),0) as total_usd FROM transfers WHERE 1=1{$storeSql} AND {$yearCol} = ? GROUP BY {$monthCol} ORDER BY month");
    $monthly->execute(array_merge($params, [$year]));

    $years = $pdo->prepare("SELECT DISTINCT {$yearCol} as y FROM transfers WHERE 1=1{$storeSql} ORDER BY y DESC");
    $years->execute($params);

    json_response([
        'saved_stats'    => $stats,
        'live_stats'     => $liveStats,
        'company_totals' => $totals->fetchAll(),
        'monthly_totals' => $monthly->fetchAll(),
        'years'          => array_column($years->fetchAll(), 'y'),
        'year'           => $year,
        'scope'          => $storeId ? 'store' : 'all',
    ]);
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $storeId = resolve_store_id(!empty($data['store_id']) ? (int)$data['store_id'] : null);
    $act = $data['action'] ?? '';

    if ($act === 'refresh') {
        $year = (int)($data['year'] ?? date('Y'));
        $pdo->prepare('DELETE FROM transfer_statistics WHERE store_id = ? AND year = ?')->execute([$storeId, $year]);

        $monthCol = sql_month('date_sent');
        $yearCol = sql_year('date_sent');
        $ins = $pdo->prepare('INSERT INTO transfer_statistics (store_id, company, month, year, transfer_count, total_usd) VALUES (?,?,?,?,?,?)');
        $live = $pdo->prepare("SELECT company, {$monthCol} as month, COUNT(*) as cnt, COALESCE(SUM(amount_usd),0) as total FROM transfers WHERE store_id = ? AND {$yearCol} = ? AND company IS NOT NULL AND company != '' GROUP BY company, {$monthCol}");
        $live->execute([$storeId, $year]);
        foreach ($live->fetchAll() as $row) {
            $ins->execute([$storeId, $row['company'], $row['month'], $year, $row['cnt'], $row['total']]);
        }
        json_response(['success' => true]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
