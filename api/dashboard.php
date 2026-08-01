<?php
/**
 * Global dashboard — one-glance business snapshot for the selected store scope.
 */
auth_require();

if (get_method() !== 'GET') {
    json_error('Method not allowed', 405);
}

$pdo = db();
$user = auth_user();
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/transaction-analytics.php';
require_once __DIR__ . '/../includes/transfer-security.php';
require_once __DIR__ . '/../includes/commission.php';

$storeId = resolve_store_filter(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
$employee = auth_resolve_employee(auth_is_store_locked());

$storeSql = $storeId ? ' AND store_id = ?' : '';
$storeParams = $storeId ? [$storeId] : [];

$today = date('Y-m-d');
// Rolling 30-day business window so the command center stays populated
// even on the first days of a new calendar month.
$monthFrom = (new DateTimeImmutable('today'))->modify('-29 days')->format('Y-m-d');
$monthTo = $today;
$calendarMonthFrom = date('Y-m-01');
$monthFromFull = $monthFrom . ' 00:00:00';
$monthToFull = $monthTo . ' 23:59:59';
$todayFromFull = $today . ' 00:00:00';
$todayToFull = $today . ' 23:59:59';

// ── Ops: caja / clients / clock ──
$todaySessions = $pdo->prepare(
    'SELECT COUNT(*) AS cnt, COALESCE(SUM(closing_balance),0) AS total
     FROM caja_sessions
     WHERE session_date = ' . sql_curdate() . $storeSql
);
$todaySessions->execute($storeParams);
$caja = $todaySessions->fetch() ?: ['cnt' => 0, 'total' => 0];

$clientCount = $pdo->prepare(
    'SELECT COUNT(DISTINCT c.id) AS cnt
     FROM clients c
     JOIN transfers t ON t.client_id = c.id
     WHERE 1=1' . ($storeId ? ' AND t.store_id = ?' : '')
);
$clientCount->execute($storeParams);
$clients = $clientCount->fetch() ?: ['cnt' => 0];

$clockedIn = $pdo->prepare(
    "SELECT COUNT(*) AS cnt FROM clock_ins
     WHERE " . sql_date_eq_today('clock_in_time') . " AND status = 'clocked_in'" . $storeSql
);
$clockedIn->execute($storeParams);
$clocked = $clockedIn->fetch() ?: ['cnt' => 0];

// ── Ledger ──
$ledgerSql = $storeSql;
$ledgerParams = $storeParams;
if ($employee && auth_is_personal_employee_scope()) {
    $ledgerSql .= ' AND employee_name = ?';
    $ledgerParams[] = $employee['name'];
}
$ledgerBalance = $pdo->prepare(
    "SELECT COALESCE(SUM(owed_to_store),0) AS owed_to_store,
            COALESCE(SUM(store_owes),0) AS store_owes
     FROM internal_ledger
     WHERE status = 'open'" . $ledgerSql
);
$ledgerBalance->execute($ledgerParams);
$ledger = $ledgerBalance->fetch() ?: ['owed_to_store' => 0, 'store_owes' => 0];

$myClockIn = null;
if ($employee) {
    $clockStmt = $pdo->prepare(
        'SELECT id, clock_in_time, clock_out_time, status, hours_worked
         FROM clock_ins
         WHERE employee_id = ? AND ' . sql_date_eq_today('clock_in_time') . '
         ORDER BY clock_in_time DESC LIMIT 1'
    );
    $clockStmt->execute([$employee['id']]);
    $myClockIn = $clockStmt->fetch() ?: null;
}

// ── Month + today transfer summaries ──
$monthSummary = txn_analytics_summary($pdo, $storeId, $monthFrom, $monthTo);
$todaySummary = txn_analytics_summary($pdo, $storeId, $today, $today);

$taxStoreSql = $storeId ? ' AND store_id = ?' : '';
$taxStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(tax),0) AS tax FROM transfers
     WHERE ' . sql_date('date_sent') . ' >= ? AND ' . sql_date('date_sent') . ' <= ?' . $taxStoreSql
);
$taxParams = [$monthFrom, $monthTo];
if ($storeId) {
    $taxParams[] = $storeId;
}
$taxStmt->execute($taxParams);
$monthTax = (float)($taxStmt->fetch()['tax'] ?? 0);

// ── Check cashing + commission ──
$checkVol = commission_volume_for_period($pdo, $storeId, $monthFrom, $monthTo);
$checkCalc = commission_calculate_checks($checkVol['amounts'] ?? []);

// ── Other services (money orders) ──
$moParams = [$monthFrom, $monthTo];
$moStore = '';
if ($storeId) {
    $moStore = ' AND bt.store_id = ?';
    $moParams[] = $storeId;
}
$moStmt = $pdo->prepare(
    "SELECT COUNT(*) AS count,
            COALESCE(SUM(ABS(bt.principal)),0) AS principal,
            COALESCE(SUM(bt.fee),0) AS fees
     FROM barri_transactions bt
     WHERE REPLACE(LOWER(COALESCE(bt.transaction_type,'')),' ','_') = 'money_order'
       AND (
            bt.client_id IS NULL
         OR LOWER(TRIM(COALESCE(bt.customer_name,''))) IN ('money order','giro postal','')
         OR bt.customer_name LIKE 'MO %'
       )
       AND bt.transaction_date >= ? AND bt.transaction_date <= ?
       {$moStore}"
);
$moStmt->execute($moParams);
$otherServices = $moStmt->fetch() ?: ['count' => 0, 'principal' => 0, 'fees' => 0];

// ── Patterns / FinCEN ──
$patterns = txn_analytics_patterns($pdo, $storeId, $monthFrom, $monthTo);
$fincenLimit = fincen_global_limit();

$senderWhere = 't.date_sent BETWEEN ? AND ?'
    . ($storeId ? ' AND t.store_id = ?' : '')
    . " AND NOT (LOWER(TRIM(COALESCE(c.name,''))) IN ('money order','giro postal','otros servicios','other services'))";
$senderParams = [$monthFromFull, $monthToFull];
if ($storeId) {
    $senderParams[] = $storeId;
}
$topStmt = $pdo->prepare(
    "SELECT c.id, c.name, c.phone,
            COUNT(t.id) AS transfer_count,
            COALESCE(SUM(t.amount_usd),0) AS total_sent,
            COALESCE(SUM(t.fee),0) AS total_fees,
            MAX(t.date_sent) AS last_transfer
     FROM transfers t
     JOIN clients c ON c.id = t.client_id
     WHERE {$senderWhere}
     GROUP BY c.id, c.name, c.phone
     ORDER BY total_sent DESC
     LIMIT 8"
);
$topStmt->execute($senderParams);
$topSenders = $topStmt->fetchAll() ?: [];
$fincenFlagged = 0;
foreach ($topSenders as $s) {
    if ((float)($s['total_sent'] ?? 0) >= $fincenLimit) {
        $fincenFlagged++;
    }
}
// Full FinCEN count across all senders (not just top 8)
$fincenStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM (
        SELECT c.id
        FROM transfers t
        JOIN clients c ON c.id = t.client_id
        WHERE {$senderWhere}
        GROUP BY c.id
        HAVING COALESCE(SUM(t.amount_usd),0) >= ?
     ) x"
);
$fincenStmt->execute(array_merge($senderParams, [$fincenLimit]));
$fincenFlagged = (int)$fincenStmt->fetchColumn();

// ── Security alerts ──
$securityAlerts = transfer_security_list($pdo, 'open', $storeId, 12);
$securityCounts = ['low' => 0, 'medium' => 0, 'high' => 0];
if ($storeId) {
    foreach ($securityAlerts as $a) {
        $sev = strtolower((string)($a['severity'] ?? 'low'));
        if (isset($securityCounts[$sev])) {
            $securityCounts[$sev]++;
        }
    }
    // Recount accurately when store filtered (list is capped)
    if (transfer_security_table_exists($pdo)) {
        $sevStmt = $pdo->prepare(
            "SELECT severity, COUNT(*) AS cnt
             FROM transfer_security_alerts
             WHERE status = 'open' AND store_id = ?
             GROUP BY severity"
        );
        $sevStmt->execute([$storeId]);
        $securityCounts = ['low' => 0, 'medium' => 0, 'high' => 0];
        foreach ($sevStmt->fetchAll() ?: [] as $row) {
            $sev = $row['severity'] ?? '';
            if (isset($securityCounts[$sev])) {
                $securityCounts[$sev] = (int)$row['cnt'];
            }
        }
    }
} else {
    $securityCounts = transfer_security_open_count_by_severity($pdo);
}
$securityTotal = $securityCounts['low'] + $securityCounts['medium'] + $securityCounts['high'];

// ── Charts: daily (month), company, type, store ──
$dayExpr = sql_date('t.date_sent');
$dailyParams = [$monthFromFull, $monthToFull];
$dailyStore = '';
if ($storeId) {
    $dailyStore = ' AND t.store_id = ?';
    $dailyParams[] = $storeId;
}
$dailyStmt = $pdo->prepare(
    "SELECT {$dayExpr} AS day, COUNT(*) AS count, COALESCE(SUM(t.amount_usd),0) AS total
     FROM transfers t
     WHERE t.date_sent BETWEEN ? AND ?{$dailyStore}
     GROUP BY {$dayExpr}
     ORDER BY day"
);
$dailyStmt->execute($dailyParams);
$dailyBreakdown = $dailyStmt->fetchAll() ?: [];

$companies = txn_analytics_by_company($pdo, $storeId, $monthFrom, $monthTo);

$typeParams = [$monthFromFull, $monthToFull];
$typeStore = '';
if ($storeId) {
    $typeStore = ' AND store_id = ?';
    $typeParams[] = $storeId;
}
$typeStmt = $pdo->prepare(
    "SELECT COALESCE(NULLIF(TRIM(transaction_type), ''), 'unknown') AS txn_type,
            COUNT(*) AS count,
            COALESCE(SUM(amount_usd),0) AS total_principal
     FROM transfers
     WHERE date_sent BETWEEN ? AND ?{$typeStore}
     GROUP BY COALESCE(NULLIF(TRIM(transaction_type), ''), 'unknown')
     ORDER BY total_principal DESC"
);
$typeStmt->execute($typeParams);
$typeBreakdown = $typeStmt->fetchAll() ?: [];
if (($checkVol['check_count'] ?? 0) > 0) {
    $typeBreakdown[] = [
        'txn_type' => 'cambio_cheque',
        'count' => (int)$checkVol['check_count'],
        'total_principal' => (float)$checkVol['volume'],
    ];
}

$storeBreakdown = [];
if (!$storeId) {
    $storeStmt = $pdo->prepare(
        "SELECT s.id AS store_id, s.name AS store_name,
                COUNT(t.id) AS count,
                COALESCE(SUM(t.amount_usd),0) AS total,
                COALESCE(SUM(t.fee),0) AS fees
         FROM transfers t
         JOIN stores s ON s.id = t.store_id
         WHERE t.date_sent BETWEEN ? AND ?
         GROUP BY s.id, s.name
         ORDER BY total DESC"
    );
    $storeStmt->execute([$monthFromFull, $monthToFull]);
    $storeBreakdown = $storeStmt->fetchAll() ?: [];
}

$principal = (float)($monthSummary['principal'] ?? 0);
$fees = (float)($monthSummary['fees'] ?? 0);
$txns = (int)($monthSummary['count'] ?? 0);
$senders = (int)($monthSummary['unique_clients'] ?? 0);

json_response([
    'store_id' => $storeId,
    'scope' => $storeId ? 'store' : 'all',
    'employee' => $employee,
    'my_clock_in' => $myClockIn,
    'period' => [
        'from' => $monthFrom,
        'to' => $monthTo,
        'label' => 'last_30_days',
        'calendar_month_from' => $calendarMonthFrom,
        'today' => $today,
    ],
    // Back-compat fields used by older clients
    'caja_sessions_today' => (int)$caja['cnt'],
    'caja_total_today' => (float)$caja['total'],
    'total_clients' => (int)$clients['cnt'],
    'month_transfers' => $txns,
    'month_transfer_usd' => $principal,
    'clocked_in_today' => (int)$clocked['cnt'],
    'ledger_owed_to_store' => (float)$ledger['owed_to_store'],
    'ledger_store_owes' => (float)$ledger['store_owes'],
    'ops' => [
        'caja_sessions_today' => (int)$caja['cnt'],
        'caja_total_today' => (float)$caja['total'],
        'clocked_in_today' => (int)$clocked['cnt'],
        'total_clients' => (int)$clients['cnt'],
    ],
    'month' => [
        'principal' => $principal,
        'fees' => $fees,
        'tax' => $monthTax,
        'grand_total' => round($principal + $fees + $monthTax, 2),
        'txns' => $txns,
        'unique_senders' => $senders,
        'avg_ticket' => $txns > 0 ? round($principal / $txns, 2) : 0.0,
        'fee_rate_pct' => $principal > 0 ? round(($fees / $principal) * 100, 2) : 0.0,
        'txns_per_sender' => $senders > 0 ? round($txns / $senders, 2) : 0.0,
        'checks_volume' => (float)($checkVol['volume'] ?? 0),
        'checks_count' => (int)($checkVol['check_count'] ?? 0),
        'checks_commission' => (float)($checkCalc['commission'] ?? 0),
        'other_services_principal' => (float)($otherServices['principal'] ?? 0),
        'other_services_count' => (int)($otherServices['count'] ?? 0),
        'other_services_fees' => (float)($otherServices['fees'] ?? 0),
    ],
    'today' => [
        'principal' => (float)($todaySummary['principal'] ?? 0),
        'fees' => (float)($todaySummary['fees'] ?? 0),
        'txns' => (int)($todaySummary['count'] ?? 0),
        'unique_senders' => (int)($todaySummary['unique_clients'] ?? 0),
    ],
    'ledger' => [
        'owed_to_store' => (float)$ledger['owed_to_store'],
        'store_owes' => (float)$ledger['store_owes'],
        'net' => (float)$ledger['owed_to_store'] - (float)$ledger['store_owes'],
    ],
    'compliance' => [
        'fincen_flagged' => $fincenFlagged,
        'fincen_limit' => $fincenLimit,
        'over_limit_clients' => (int)($patterns['over_limit_clients_count'] ?? 0),
        'high_frequency_days' => count($patterns['high_frequency_clients'] ?? []),
        'multi_store_clients' => (int)($patterns['multi_store_clients_count'] ?? 0),
        'security_alerts' => array_merge($securityCounts, ['total' => $securityTotal]),
    ],
    'charts' => [
        'daily' => $dailyBreakdown,
        'companies' => array_map(static function ($c) {
            return [
                'company' => $c['company'] ?? '—',
                'count' => (int)($c['count'] ?? 0),
                'total_principal' => (float)($c['principal'] ?? 0),
            ];
        }, $companies),
        'types' => $typeBreakdown,
        'stores' => $storeBreakdown,
    ],
    'alerts' => [
        'security' => array_slice($securityAlerts, 0, 8),
        'patterns' => array_slice($patterns['high_frequency_clients'] ?? [], 0, 8),
    ],
    'top_senders' => $topSenders,
]);
