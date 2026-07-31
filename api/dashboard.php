<?php
auth_require();

if (get_method() !== 'GET') json_error('Method not allowed', 405);

$pdo = db();
$user = auth_user();
$storeId = resolve_store_filter(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
$employee = auth_resolve_employee(auth_is_store_locked());

$storeSql = $storeId ? ' AND store_id = ?' : '';
$storeParams = $storeId ? [$storeId] : [];

$todaySessions = $pdo->prepare('SELECT COUNT(*) as cnt, COALESCE(SUM(closing_balance),0) as total FROM caja_sessions WHERE session_date = ' . sql_curdate() . $storeSql);
$todaySessions->execute($storeParams);
$caja = $todaySessions->fetch();

$clientCount = $pdo->prepare('SELECT COUNT(DISTINCT c.id) as cnt FROM clients c JOIN transfers t ON t.client_id = c.id WHERE 1=1' . ($storeId ? ' AND t.store_id = ?' : ''));
$clientCount->execute($storeParams);
$clients = $clientCount->fetch();

$monthTransfers = $pdo->prepare('SELECT COUNT(*) as cnt, COALESCE(SUM(amount_usd),0) as total FROM transfers WHERE ' . sql_same_month_year('date_sent') . $storeSql);
$monthTransfers->execute($storeParams);
$transfers = $monthTransfers->fetch();

$clockedIn = $pdo->prepare('SELECT COUNT(*) as cnt FROM clock_ins WHERE ' . sql_date_eq_today('clock_in_time') . " AND status = 'clocked_in'" . $storeSql);
$clockedIn->execute($storeParams);
$clocked = $clockedIn->fetch();

$ledgerSql = $storeSql;
$ledgerParams = $storeParams;
if ($employee && auth_is_personal_employee_scope()) {
    $ledgerSql .= ' AND employee_name = ?';
    $ledgerParams[] = $employee['name'];
}

$ledgerBalance = $pdo->prepare("SELECT COALESCE(SUM(owed_to_store),0) as owed_to_store, COALESCE(SUM(store_owes),0) as store_owes FROM internal_ledger WHERE status = 'open'" . $ledgerSql);
$ledgerBalance->execute($ledgerParams);
$ledger = $ledgerBalance->fetch();

$myClockIn = null;
if ($employee) {
    $clockStmt = $pdo->prepare('SELECT id, clock_in_time, clock_out_time, status, hours_worked FROM clock_ins WHERE employee_id = ? AND ' . sql_date_eq_today('clock_in_time') . ' ORDER BY clock_in_time DESC LIMIT 1');
    $clockStmt->execute([$employee['id']]);
    $myClockIn = $clockStmt->fetch() ?: null;
}

json_response([
    'store_id'            => $storeId,
    'scope'               => $storeId ? 'store' : 'all',
    'employee'            => $employee,
    'my_clock_in'         => $myClockIn,
    'caja_sessions_today' => (int)$caja['cnt'],
    'caja_total_today'    => (float)$caja['total'],
    'total_clients'       => (int)$clients['cnt'],
    'month_transfers'     => (int)$transfers['cnt'],
    'month_transfer_usd'  => (float)$transfers['total'],
    'clocked_in_today'    => (int)$clocked['cnt'],
    'ledger_owed_to_store' => (float)$ledger['owed_to_store'],
    'ledger_store_owes'    => (float)$ledger['store_owes'],
]);
