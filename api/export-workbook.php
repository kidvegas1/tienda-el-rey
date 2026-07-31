<?php
$user = auth_require();
$method = get_method();
$pdo = db();

if ($method !== 'GET') json_error('Method not allowed', 405);

$storeId = resolve_store_filter(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-t');
$dateFromFull = $dateFrom . ' 00:00:00';
$dateToFull = $dateTo . ' 23:59:59';

$storeName = 'All Stores';
if ($storeId) {
    $s = $pdo->prepare('SELECT name FROM stores WHERE id = ?');
    $s->execute([$storeId]);
    $row = $s->fetch();
    if ($row) $storeName = $row['name'];
}

$storeWhere = $storeId ? ' AND store_id = ?' : '';
$storeParam = $storeId ? [$storeId] : [];

// CAJA — entries from caja_entries joined with caja_sessions in date range
$cajaQ = "SELECT ce.company, ce.cash_in, ce.checks_debits, ce.total, cs.session_date, cs.cashier_name
    FROM caja_entries ce
    JOIN caja_sessions cs ON cs.id = ce.session_id
    WHERE cs.session_date BETWEEN ? AND ?{$storeWhere}
    ORDER BY cs.session_date, ce.sort_order";
$stmt = $pdo->prepare($cajaQ);
$stmt->execute(array_merge([$dateFrom, $dateTo], $storeParam));
$caja = $stmt->fetchAll();

// ESTADISTICAS GIROS — transfer_statistics in date range
$monthPad = db_is_pgsql() ? "LPAD(month::text, 2, '0')" : 'LPAD(month, 2, \'0\')';
$statsQ = "SELECT company, month, year, transfer_count, total_usd
    FROM transfer_statistics
    WHERE CONCAT(year,'-',{$monthPad},'-01') BETWEEN ? AND ?{$storeWhere}
    ORDER BY year, month, company";
$stmt = $pdo->prepare($statsQ);
$stmt->execute(array_merge([$dateFrom, $dateTo], $storeParam));
$statistics = $stmt->fetchAll();

// Internal ledger
$ledgerQ = "SELECT employee_name, description, owed_to_store, store_owes, entry_date, notes, status, paid_at
    FROM internal_ledger
    WHERE entry_date BETWEEN ? AND ?{$storeWhere}
    ORDER BY entry_date";
$stmt = $pdo->prepare($ledgerQ);
$stmt->execute(array_merge([$dateFrom, $dateTo], $storeParam));
$internalLedger = $stmt->fetchAll();

// CONTABILIDAD — accounting_entries
$acctQ = "SELECT category, description, amount, entry_type, entry_date, notes
    FROM accounting_entries
    WHERE entry_date BETWEEN ? AND ?{$storeWhere}
    ORDER BY entry_date";
$stmt = $pdo->prepare($acctQ);
$stmt->execute(array_merge([$dateFrom, $dateTo], $storeParam));
$accounting = $stmt->fetchAll();

// PLACAS
$platesQ = "SELECT client_name, phone, vin, service_type, delivery_date, payment, balance, total, status, notes
    FROM plates
    WHERE created_at BETWEEN ? AND ?{$storeWhere}
    ORDER BY created_at";
$stmt = $pdo->prepare($platesQ);
$stmt->execute(array_merge([$dateFromFull, $dateToFull], $storeParam));
$plates = $stmt->fetchAll();

// BASE DE DATOS CLIENTES — clients with transfers at scoped store (managers always scoped)
if ($storeId) {
    $clientsQ = "SELECT c.client_code, c.name, c.phone, c.monthly_limit, c.income_verified,
        (SELECT COALESCE(SUM(amount_usd),0) FROM transfers WHERE client_id = c.id AND store_id = ? AND date_sent BETWEEN ? AND ?) as period_sent,
        (SELECT COUNT(*) FROM transfers WHERE client_id = c.id AND store_id = ? AND date_sent BETWEEN ? AND ?) as period_transfers,
        (SELECT COALESCE(SUM(amount_usd),0) FROM transfers WHERE client_id = c.id AND store_id = ?) as total_sent
        FROM clients c
        WHERE EXISTS (SELECT 1 FROM transfers t WHERE t.client_id = c.id AND t.store_id = ?)
        ORDER BY c.name";
    $stmt = $pdo->prepare($clientsQ);
    $stmt->execute([$storeId, $dateFromFull, $dateToFull, $storeId, $dateFromFull, $dateToFull, $storeId, $storeId]);
} else {
    $clientsQ = "SELECT c.client_code, c.name, c.phone, c.monthly_limit, c.income_verified,
        (SELECT COALESCE(SUM(amount_usd),0) FROM transfers WHERE client_id = c.id AND date_sent BETWEEN ? AND ?) as period_sent,
        (SELECT COUNT(*) FROM transfers WHERE client_id = c.id AND date_sent BETWEEN ? AND ?) as period_transfers,
        (SELECT COALESCE(SUM(amount_usd),0) FROM transfers WHERE client_id = c.id) as total_sent
        FROM clients c ORDER BY c.name";
    $stmt = $pdo->prepare($clientsQ);
    $stmt->execute([$dateFromFull, $dateToFull, $dateFromFull, $dateToFull]);
}
$clients = $stmt->fetchAll();

// TRANSFERS — all transfers in range
$txnWhere = 't.date_sent BETWEEN ? AND ?';
$txnParams = [$dateFromFull, $dateToFull];
if ($storeId) {
    $txnWhere .= ' AND t.store_id = ?';
    $txnParams[] = $storeId;
}
$txnQ = "SELECT t.date_sent, s.name as store_name, c.name as client_name, t.beneficiary, t.company,
    t.transaction_code, t.transaction_type, t.amount_usd, t.fee, t.tax,
    (t.amount_usd + COALESCE(t.fee,0) + COALESCE(t.tax,0)) as total, t.source
    FROM transfers t
    LEFT JOIN clients c ON c.id = t.client_id
    LEFT JOIN stores s ON s.id = t.store_id
    WHERE {$txnWhere}
    ORDER BY t.date_sent";
$stmt = $pdo->prepare($txnQ);
$stmt->execute($txnParams);
$transfers = $stmt->fetchAll();

// EVENTS
$eventsQ = "SELECT client_name, phone, event_date, deposit, balance, total, color_theme, package, status, notes
    FROM events
    WHERE event_date BETWEEN ? AND ?{$storeWhere}
    ORDER BY event_date";
$stmt = $pdo->prepare($eventsQ);
$stmt->execute(array_merge([$dateFrom, $dateTo], $storeParam));
$events = $stmt->fetchAll();

// INVENTORY — current snapshot
$invQ = "SELECT product_name, quantity, description, cost_price, retail_price, low_stock_threshold
    FROM inventory WHERE 1=1{$storeWhere} ORDER BY product_name";
$stmt = $pdo->prepare($invQ);
$stmt->execute($storeParam);
$inventory = $stmt->fetchAll();

json_response([
    'store_name' => $storeName,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'caja' => $caja,
    'statistics' => $statistics,
    'internal_ledger' => $internalLedger,
    'accounting' => $accounting,
    'plates' => $plates,
    'clients' => $clients,
    'transfers' => $transfers,
    'events' => $events,
    'inventory' => $inventory,
]);
