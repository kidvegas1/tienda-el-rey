<?php
$user = auth_require();
$method = get_method();
$pdo = db();

/** Admin-only staff/payroll management. Managers may list own-store active employees for clock-in. */
function employees_require_admin(): void {
    if (!auth_is_admin()) {
        json_error('Admin access required', 403);
    }
}

function employees_parse_date(?string $value, string $fallback): string {
    $value = trim((string)$value);
    if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    return $fallback;
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'payroll') {
        employees_require_admin();
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $weekStart = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
        $from = employees_parse_date($_GET['from'] ?? null, $weekStart);
        $to = employees_parse_date($_GET['to'] ?? null, $today);
        if ($from > $to) {
            json_error('Start date must be on or before end date', 400);
        }
        $storeId = !empty($_GET['store_id']) ? (int)$_GET['store_id'] : null;

        $where = ['1=1'];
        $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
        if ($storeId) {
            $where[] = 'e.store_id = ?';
            $params[] = $storeId;
        }
        $whereSql = implode(' AND ', $where);

        $sql = "SELECT e.id, e.name, e.phone, e.hourly_rate, e.status, e.store_id, s.name AS store_name,
            COALESCE(SUM(ci.hours_worked), 0) AS hours_worked,
            COUNT(ci.id) AS shift_count
            FROM employees e
            LEFT JOIN stores s ON s.id = e.store_id
            LEFT JOIN clock_ins ci ON ci.employee_id = e.id
                AND ci.clock_in_time >= ?
                AND ci.clock_in_time <= ?
                AND ci.status = 'clocked_out'
            WHERE {$whereSql}
            GROUP BY e.id, e.name, e.phone, e.hourly_rate, e.status, e.store_id, s.name
            ORDER BY s.name ASC, e.name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $totalHours = 0.0;
        $totalPay = 0.0;
        foreach ($rows as &$row) {
            $hours = (float)($row['hours_worked'] ?? 0);
            $rate = $row['hourly_rate'] !== null && $row['hourly_rate'] !== ''
                ? (float)$row['hourly_rate']
                : null;
            $estimated = $rate !== null ? round($hours * $rate, 2) : null;
            $row['hours_worked'] = round($hours, 2);
            $row['hourly_rate'] = $rate;
            $row['estimated_pay'] = $estimated;
            $totalHours += $hours;
            if ($estimated !== null) {
                $totalPay += $estimated;
            }
        }
        unset($row);

        json_response([
            'from' => $from,
            'to' => $to,
            'store_id' => $storeId,
            'rows' => $rows,
            'totals' => [
                'hours_worked' => round($totalHours, 2),
                'estimated_pay' => round($totalPay, 2),
                'employee_count' => count($rows),
            ],
        ]);
    }

    // Default list
    $storeId = !empty($_GET['store_id']) ? (int)$_GET['store_id'] : null;
    $includeInactive = ($_GET['include_inactive'] ?? '') === '1' || ($_GET['include_inactive'] ?? '') === 'true';

    if (!auth_is_admin()) {
        $storeId = (int)($_SESSION['store_id'] ?? 0);
        if ($storeId <= 0) {
            json_error('No store assigned', 403);
        }
        $includeInactive = false;
    }

    $where = ['1=1'];
    $params = [];
    if ($storeId) {
        $where[] = 'e.store_id = ?';
        $params[] = $storeId;
    }
    if (!$includeInactive) {
        $where[] = "e.status = 'active'";
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT e.id, e.store_id, e.user_id, e.name, e.phone, e.hourly_rate, e.status, e.created_at,
            s.name AS store_name, u.email AS user_email, u.role AS user_role, u.active AS user_active
         FROM employees e
         LEFT JOIN stores s ON s.id = e.store_id
         LEFT JOIN users u ON u.id = e.user_id
         WHERE {$whereSql}
         ORDER BY e.status ASC, s.name ASC, e.name ASC"
    );
    $stmt->execute($params);
    json_response(['employees' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    csrf_verify();
    employees_require_admin();
    $data = get_json_body();
    $act = $data['action'] ?? 'create';

    if ($act === 'create') {
        validate_required($data, ['name', 'store_id']);
        $storeId = (int)$data['store_id'];
        $check = $pdo->prepare('SELECT id FROM stores WHERE id = ? AND ' . sql_is_active());
        $check->execute([$storeId]);
        if (!$check->fetch()) {
            json_error('Store not found', 404);
        }
        $name = sanitize($data['name']);
        $phone = sanitize($data['phone'] ?? '');
        $rate = isset($data['hourly_rate']) && $data['hourly_rate'] !== '' && $data['hourly_rate'] !== null
            ? max(0, (float)$data['hourly_rate'])
            : null;
        $pdo->prepare('INSERT INTO employees (store_id, name, phone, hourly_rate, status) VALUES (?,?,?,?,?)')
            ->execute([$storeId, $name, $phone !== '' ? $phone : null, $rate, 'active']);
        json_response(['success' => true, 'id' => sql_last_insert_id($pdo, 'employees')], 201);
    }

    if ($act === 'update') {
        validate_required($data, ['id']);
        $id = (int)$data['id'];
        $existing = $pdo->prepare('SELECT id, user_id FROM employees WHERE id = ?');
        $existing->execute([$id]);
        $row = $existing->fetch();
        if (!$row) {
            json_error('Employee not found', 404);
        }

        $fields = [];
        $params = [];
        if (isset($data['name'])) {
            $fields[] = 'name = ?';
            $params[] = sanitize($data['name']);
        }
        if (array_key_exists('phone', $data)) {
            $phone = sanitize((string)$data['phone']);
            $fields[] = 'phone = ?';
            $params[] = $phone !== '' ? $phone : null;
        }
        if (array_key_exists('hourly_rate', $data)) {
            $rate = $data['hourly_rate'] === '' || $data['hourly_rate'] === null
                ? null
                : max(0, (float)$data['hourly_rate']);
            $fields[] = 'hourly_rate = ?';
            $params[] = $rate;
        }
        if (isset($data['store_id'])) {
            $storeId = (int)$data['store_id'];
            $check = $pdo->prepare('SELECT id FROM stores WHERE id = ? AND ' . sql_is_active());
            $check->execute([$storeId]);
            if (!$check->fetch()) {
                json_error('Store not found', 404);
            }
            $fields[] = 'store_id = ?';
            $params[] = $storeId;
        }
        if (isset($data['status'])) {
            $status = sanitize($data['status']);
            if (!in_array($status, ['active', 'inactive'], true)) {
                json_error('Invalid status', 400);
            }
            $fields[] = 'status = ?';
            $params[] = $status;
        }
        if (!$fields) {
            json_error('Nothing to update', 400);
        }
        $params[] = $id;
        $pdo->prepare('UPDATE employees SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

        // Mirror name/store onto linked login user when present.
        if (!empty($row['user_id'])) {
            $userFields = [];
            $userParams = [];
            if (isset($data['name'])) {
                $userFields[] = 'name = ?';
                $userParams[] = sanitize($data['name']);
            }
            if (isset($data['store_id'])) {
                $userFields[] = 'store_id = ?';
                $userParams[] = (int)$data['store_id'];
            }
            if (isset($data['status'])) {
                $userFields[] = 'active = ?';
                $userParams[] = db_bool($data['status'] === 'active');
            }
            if ($userFields) {
                $userParams[] = (int)$row['user_id'];
                $pdo->prepare('UPDATE users SET ' . implode(', ', $userFields) . ' WHERE id = ?')->execute($userParams);
            }
        }

        json_response(['success' => true]);
    }

    if ($act === 'deactivate') {
        validate_required($data, ['id']);
        $id = (int)$data['id'];
        $existing = $pdo->prepare('SELECT id, user_id FROM employees WHERE id = ?');
        $existing->execute([$id]);
        $row = $existing->fetch();
        if (!$row) {
            json_error('Employee not found', 404);
        }
        $pdo->prepare("UPDATE employees SET status = 'inactive' WHERE id = ?")->execute([$id]);
        if (!empty($row['user_id'])) {
            $pdo->prepare('UPDATE users SET active = ' . sql_bool(false) . ' WHERE id = ? AND role <> \'admin\'')
                ->execute([(int)$row['user_id']]);
        }
        json_response(['success' => true]);
    }

    if ($act === 'reactivate') {
        validate_required($data, ['id']);
        $id = (int)$data['id'];
        $existing = $pdo->prepare('SELECT id, user_id FROM employees WHERE id = ?');
        $existing->execute([$id]);
        $row = $existing->fetch();
        if (!$row) {
            json_error('Employee not found', 404);
        }
        $pdo->prepare("UPDATE employees SET status = 'active' WHERE id = ?")->execute([$id]);
        if (!empty($row['user_id'])) {
            $pdo->prepare('UPDATE users SET active = ' . sql_bool(true) . ' WHERE id = ?')->execute([(int)$row['user_id']]);
        }
        json_response(['success' => true]);
    }

    if ($act === 'delete') {
        validate_required($data, ['id']);
        $id = (int)$data['id'];
        $existing = $pdo->prepare('SELECT id, user_id FROM employees WHERE id = ?');
        $existing->execute([$id]);
        $row = $existing->fetch();
        if (!$row) {
            json_error('Employee not found', 404);
        }
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM clock_ins WHERE employee_id = ?');
        $countStmt->execute([$id]);
        $clockCount = (int)$countStmt->fetchColumn();
        if ($clockCount > 0) {
            // Preserve payroll history — soft delete only.
            $pdo->prepare("UPDATE employees SET status = 'inactive' WHERE id = ?")->execute([$id]);
            if (!empty($row['user_id'])) {
                $pdo->prepare('UPDATE users SET active = ' . sql_bool(false) . ' WHERE id = ? AND role <> \'admin\'')
                    ->execute([(int)$row['user_id']]);
            }
            json_response([
                'success' => true,
                'soft_deleted' => true,
                'message' => 'Employee has clock history and was deactivated instead of permanently deleted.',
            ]);
        }
        $pdo->prepare('DELETE FROM employees WHERE id = ?')->execute([$id]);
        json_response(['success' => true, 'soft_deleted' => false]);
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
