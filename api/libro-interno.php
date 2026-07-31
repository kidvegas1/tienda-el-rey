<?php
$user = auth_require();
$method = get_method();
$pdo = db();
require_once __DIR__ . '/../includes/ledger.php';
require_once __DIR__ . '/../includes/commission.php';

if ($method === 'GET') {
    $storeId = resolve_store_filter(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
    $resolvedEmployee = auth_resolve_employee(auth_is_store_locked());
    $employeeFilter = $_GET['employee'] ?? '';
    if ($resolvedEmployee && auth_is_personal_employee_scope()) {
        $employeeFilter = $resolvedEmployee['name'];
    }

    $statusFilter = $_GET['status'] ?? 'open';
    if (!ledger_valid_status_filter($statusFilter)) {
        json_error('Invalid status filter');
    }

    $commFrom = $_GET['commission_from'] ?? null;
    $commTo = $_GET['commission_to'] ?? null;
    if ($commFrom !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$commFrom)) {
        json_error('Invalid commission_from date');
    }
    if ($commTo !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$commTo)) {
        json_error('Invalid commission_to date');
    }

    // Commission-only payload (smart tracker refresh)
    if (($_GET['action'] ?? '') === 'commission') {
        $empForComm = $employeeFilter !== '' ? $employeeFilter : null;
        json_response([
            'commission' => commission_tracker_payload($pdo, $storeId, $commFrom, $commTo, $empForComm),
            'scope' => $storeId ? 'store' : 'all',
        ]);
    }

    $storeSql = store_filter_sql('sl.store_id', $storeId);
    $baseWhere = 'WHERE 1=1' . $storeSql;
    $params = [];
    if ($storeId) $params[] = $storeId;

    if ($employeeFilter) {
        $baseWhere .= ' AND sl.employee_name = ?';
        $params[] = $employeeFilter;
    }

    $listWhere = $baseWhere;
    $listParams = $params;
    if ($statusFilter !== 'all') {
        $listWhere .= ' AND sl.status = ?';
        $listParams[] = $statusFilter;
    }

    $stmt = $pdo->prepare("SELECT sl.*, s.name as store_name, u.name as paid_by_name FROM internal_ledger sl LEFT JOIN stores s ON s.id = sl.store_id LEFT JOIN users u ON u.id = sl.paid_by_user_id {$listWhere} ORDER BY sl.entry_date DESC, sl.id DESC LIMIT 200");
    $stmt->execute($listParams);
    $entries = $stmt->fetchAll();

    $totalsWhere = $baseWhere . ledger_open_status_sql('sl');
    $totals = $pdo->prepare("SELECT
        COALESCE(SUM(owed_to_store),0) as total_owed_to_store,
        COALESCE(SUM(store_owes),0) as total_store_owes
        FROM internal_ledger sl {$totalsWhere}");
    $totals->execute($params);
    $sums = $totals->fetch();

    if ($storeId) {
        $employees = $pdo->prepare('SELECT DISTINCT employee_name FROM internal_ledger WHERE store_id = ? AND employee_name IS NOT NULL AND employee_name != \'\' ORDER BY employee_name');
        $employees->execute([$storeId]);
    } else {
        $employees = $pdo->query('SELECT DISTINCT employee_name FROM internal_ledger WHERE employee_name IS NOT NULL AND employee_name != \'\' ORDER BY employee_name');
    }

    $empForComm = $employeeFilter !== '' ? $employeeFilter : null;
    $commission = commission_tracker_payload($pdo, $storeId, $commFrom, $commTo, $empForComm);

    json_response([
        'entries'            => $entries,
        'total_owed_to_store' => (float)$sums['total_owed_to_store'],
        'total_store_owes'    => (float)$sums['total_store_owes'],
        'difference'         => (float)$sums['total_owed_to_store'] - (float)$sums['total_store_owes'],
        'employees'          => array_column($employees->fetchAll(), 'employee_name'),
        'current_employee'   => $resolvedEmployee,
        'employee_locked'    => $resolvedEmployee && auth_is_personal_employee_scope(),
        'scope'              => $storeId ? 'store' : 'all',
        'status_filter'      => $statusFilter,
        'commission'         => $commission,
    ]);
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $storeId = resolve_store_id(!empty($data['store_id']) ? (int)$data['store_id'] : null);
    $act = $data['action'] ?? '';

    $resolvedEmployee = auth_resolve_employee(auth_is_store_locked());
    $employeeNameForEntry = ($resolvedEmployee && auth_is_personal_employee_scope())
        ? $resolvedEmployee['name']
        : sanitize($data['employee_name'] ?? '');

    if ($act === 'create') {
        validate_required($data, ['description', 'entry_date']);
        $stmt = $pdo->prepare('INSERT INTO internal_ledger (store_id, employee_name, description, owed_to_store, store_owes, entry_date, notes) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([
            $storeId,
            $employeeNameForEntry,
            sanitize($data['description']),
            (float)($data['owed_to_store'] ?? 0),
            (float)($data['store_owes'] ?? 0),
            $data['entry_date'],
            sanitize($data['notes'] ?? ''),
        ]);
        json_response(['success' => true, 'id' => sql_last_insert_id($pdo, 'internal_ledger')], 201);
    }

    if ($act === 'update') {
        validate_required($data, ['id', 'description']);
        $check = $pdo->prepare('SELECT status FROM internal_ledger WHERE id = ? AND store_id = ?');
        $check->execute([(int)$data['id'], $storeId]);
        $existing = $check->fetch();
        if (!$existing) {
            json_error('Entry not found', 404);
        }
        if (!ledger_entry_is_open($existing)) {
            json_error('Cannot update a paid entry');
        }
        $stmt = $pdo->prepare('UPDATE internal_ledger SET employee_name = ?, description = ?, owed_to_store = ?, store_owes = ?, entry_date = ?, notes = ? WHERE id = ? AND store_id = ?');
        $stmt->execute([
            $employeeNameForEntry,
            sanitize($data['description']),
            (float)($data['owed_to_store'] ?? 0),
            (float)($data['store_owes'] ?? 0),
            $data['entry_date'],
            sanitize($data['notes'] ?? ''),
            (int)$data['id'],
            $storeId,
        ]);
        json_response(['success' => true]);
    }

    if ($act === 'mark_paid') {
        if (!ledger_can_settle_user($user)) {
            json_error('Admin or manager access required', 403);
        }
        validate_required($data, ['id']);
        $id = (int)$data['id'];
        $row = $pdo->prepare('SELECT owed_to_store, store_owes, status FROM internal_ledger WHERE id = ? AND store_id = ?');
        $row->execute([$id, $storeId]);
        $entry = $row->fetch();
        if (!$entry) {
            json_error('Entry not found', 404);
        }
        $markError = ledger_mark_paid_error($entry);
        if ($markError !== null) {
            json_error($markError);
        }
        $stmt = $pdo->prepare('UPDATE internal_ledger SET status = ?, paid_at = ' . sql_now() . ', paid_by_user_id = ? WHERE id = ? AND store_id = ? AND status = ?');
        $stmt->execute(['paid', (int)$user['id'], $id, $storeId, 'open']);
        if ($stmt->rowCount() === 0) {
            json_error('Entry not found or already paid');
        }
        json_response(['success' => true]);
    }

    if ($act === 'reopen') {
        if (!ledger_can_settle_user($user)) {
            json_error('Admin or manager access required', 403);
        }
        validate_required($data, ['id']);
        $id = (int)$data['id'];
        $row = $pdo->prepare('SELECT status FROM internal_ledger WHERE id = ? AND store_id = ?');
        $row->execute([$id, $storeId]);
        $entry = $row->fetch();
        if (!$entry) {
            json_error('Entry not found', 404);
        }
        $reopenError = ledger_reopen_error($entry);
        if ($reopenError !== null) {
            json_error($reopenError);
        }
        $stmt = $pdo->prepare('UPDATE internal_ledger SET status = ?, paid_at = NULL, paid_by_user_id = NULL WHERE id = ? AND store_id = ? AND status = ?');
        $stmt->execute(['open', $id, $storeId, 'paid']);
        if ($stmt->rowCount() === 0) {
            json_error('Entry not found or not paid');
        }
        json_response(['success' => true]);
    }

    if ($act === 'delete') {
        validate_required($data, ['id']);
        $check = $pdo->prepare('SELECT status FROM internal_ledger WHERE id = ? AND store_id = ?');
        $check->execute([(int)$data['id'], $storeId]);
        $existing = $check->fetch();
        if (!$existing) {
            json_error('Entry not found', 404);
        }
        if (!ledger_entry_is_open($existing)) {
            json_error('Cannot delete a paid entry');
        }
        $stmt = $pdo->prepare('DELETE FROM internal_ledger WHERE id = ? AND store_id = ?');
        $stmt->execute([(int)$data['id'], $storeId]);
        json_response(['success' => true]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
