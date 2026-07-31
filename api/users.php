<?php
$user = auth_require_admin();
$method = get_method();
$pdo = db();

if ($method === 'GET') {
    $storeId = !empty($_GET['store_id']) ? (int)$_GET['store_id'] : null;
    $sql = 'SELECT u.id, u.name, u.email, u.role, u.store_id, u.active, u.created_at, s.name AS store_name
            FROM users u
            LEFT JOIN stores s ON s.id = u.store_id';
    $params = [];
    if ($storeId) {
        $sql .= ' WHERE u.store_id = ?';
        $params[] = $storeId;
    }
    $sql .= ' ORDER BY u.name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_response(['users' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $act = $data['action'] ?? 'create';

    if ($act === 'create') {
        validate_required($data, ['name', 'email', 'password', 'role']);
        $role = sanitize($data['role']);
        $allowed = ['admin', 'manager', 'cashier', 'employee'];
        if (!in_array($role, $allowed, true)) {
            json_error('Invalid role', 400);
        }
        $storeId = !empty($data['store_id']) ? (int)$data['store_id'] : null;
        if ($role !== 'admin' && !$storeId) {
            json_error('Store is required for non-admin users', 400);
        }
        if ($storeId) {
            $check = $pdo->prepare('SELECT id FROM stores WHERE id = ? AND ' . sql_is_active());
            $check->execute([$storeId]);
            if (!$check->fetch()) {
                json_error('Store not found', 404);
            }
        }
        $email = strtolower(trim($data['email']));
        $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $dup->execute([$email]);
        if ($dup->fetch()) {
            json_error('Email already in use', 400);
        }
        if (strlen($data['password']) < 8) {
            json_error('Password must be at least 8 characters', 400);
        }
        $hash = password_hash($data['password'], PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO users (name, email, password_hash, role, store_id, active) VALUES (?,?,?,?,?, ' . sql_bool(true) . ')')
            ->execute([
                sanitize($data['name']),
                $email,
                $hash,
                $role,
                $role === 'admin' ? ($storeId ?: null) : $storeId,
            ]);
        $userId = sql_last_insert_id($pdo, 'users');
        if (in_array($role, ['manager', 'cashier', 'employee'], true) && $storeId) {
            $pdo->prepare('INSERT INTO employees (store_id, user_id, name) VALUES (?,?,?)')
                ->execute([$storeId, $userId, sanitize($data['name'])]);
        }
        json_response(['success' => true, 'id' => $userId], 201);
    }

    if ($act === 'update') {
        validate_required($data, ['id']);
        $id = (int)$data['id'];
        $existing = $pdo->prepare('SELECT id, role, name, email, store_id FROM users WHERE id = ?');
        $existing->execute([$id]);
        $row = $existing->fetch();
        if (!$row) {
            json_error('User not found', 404);
        }
        $fields = [];
        $params = [];
        $newName = null;
        $newStoreId = null;
        if (isset($data['name'])) {
            $newName = sanitize($data['name']);
            $fields[] = 'name = ?';
            $params[] = $newName;
        }
        if (isset($data['email'])) {
            $email = strtolower(trim((string)$data['email']));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                json_error('Valid email is required', 400);
            }
            $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $dup->execute([$email, $id]);
            if ($dup->fetch()) {
                json_error('Email already in use', 400);
            }
            $fields[] = 'email = ?';
            $params[] = $email;
        }
        if (isset($data['role'])) {
            $role = sanitize($data['role']);
            if (!in_array($role, ['admin', 'manager', 'cashier', 'employee'], true)) {
                json_error('Invalid role', 400);
            }
            if ($row['role'] === 'admin' && $role !== 'admin') {
                $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND " . sql_is_active())->fetchColumn();
                if ($adminCount <= 1) {
                    json_error('Cannot demote the last active admin', 400);
                }
            }
            $fields[] = 'role = ?';
            $params[] = $role;
        }
        if (array_key_exists('store_id', $data)) {
            $storeId = $data['store_id'] !== null && $data['store_id'] !== '' ? (int)$data['store_id'] : null;
            $effectiveRole = isset($data['role']) ? sanitize($data['role']) : $row['role'];
            if ($effectiveRole !== 'admin' && !$storeId) {
                json_error('Store is required for non-admin users', 400);
            }
            if ($storeId) {
                $check = $pdo->prepare('SELECT id FROM stores WHERE id = ? AND ' . sql_is_active());
                $check->execute([$storeId]);
                if (!$check->fetch()) {
                    json_error('Store not found', 404);
                }
            }
            $newStoreId = $storeId;
            $fields[] = 'store_id = ?';
            $params[] = $storeId;
        }
        if (!empty($data['password'])) {
            if (strlen($data['password']) < 8) {
                json_error('Password must be at least 8 characters', 400);
            }
            // Changing your own password requires proving the current one.
            if ($id === (int)$user['id']) {
                $current = (string)($data['current_password'] ?? '');
                if ($current === '') {
                    json_error('Current password is required to change your own password', 400);
                }
                $hashStmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
                $hashStmt->execute([$id]);
                $hashRow = $hashStmt->fetch();
                if (!$hashRow || !password_verify($current, $hashRow['password_hash'])) {
                    json_error('Current password is incorrect', 400);
                }
                if ($current === (string)$data['password']) {
                    json_error('New password must be different from the current password', 400);
                }
            }
            $fields[] = 'password_hash = ?';
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        if (isset($data['active'])) {
            $active = (bool)$data['active'];
            if (!$active && $id === (int)$user['id']) {
                json_error('You cannot deactivate your own account', 400);
            }
            if (!$active && $row['role'] === 'admin') {
                $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND " . sql_is_active())->fetchColumn();
                if ($adminCount <= 1) {
                    json_error('Cannot deactivate the last active admin', 400);
                }
            }
            $fields[] = 'active = ?';
            $params[] = db_bool($active);
        }
        if (!$fields) {
            json_error('Nothing to update', 400);
        }
        $params[] = $id;
        $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

        // Keep linked employees row in sync when editing managers/staff.
        $empFields = [];
        $empParams = [];
        if ($newName !== null) {
            $empFields[] = 'name = ?';
            $empParams[] = $newName;
        }
        if ($newStoreId !== null) {
            $empFields[] = 'store_id = ?';
            $empParams[] = $newStoreId;
        }
        if (isset($data['active'])) {
            $empFields[] = 'status = ?';
            $empParams[] = (bool)$data['active'] ? 'active' : 'inactive';
        }
        if ($empFields) {
            $empParams[] = $id;
            $pdo->prepare('UPDATE employees SET ' . implode(', ', $empFields) . ' WHERE user_id = ?')->execute($empParams);
        }

        json_response(['success' => true]);
    }

    if ($act === 'deactivate') {
        validate_required($data, ['id']);
        $id = (int)$data['id'];
        if ($id === (int)$user['id']) {
            json_error('You cannot deactivate your own account', 400);
        }
        $existing = $pdo->prepare('SELECT id, role FROM users WHERE id = ?');
        $existing->execute([$id]);
        $row = $existing->fetch();
        if (!$row) {
            json_error('User not found', 404);
        }
        if ($row['role'] === 'admin') {
            $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND " . sql_is_active())->fetchColumn();
            if ($adminCount <= 1) {
                json_error('Cannot remove the last active admin', 400);
            }
        }
        $pdo->prepare('UPDATE users SET active = ' . sql_bool(false) . ' WHERE id = ?')->execute([$id]);
        $pdo->prepare("UPDATE employees SET status = 'inactive' WHERE user_id = ?")->execute([$id]);
        json_response(['success' => true]);
    }

    if ($act === 'reactivate') {
        validate_required($data, ['id']);
        $id = (int)$data['id'];
        $existing = $pdo->prepare('SELECT id, role, store_id FROM users WHERE id = ?');
        $existing->execute([$id]);
        $row = $existing->fetch();
        if (!$row) {
            json_error('User not found', 404);
        }
        if ($row['role'] !== 'admin' && empty($row['store_id'])) {
            json_error('Assign a store before reactivating this user', 400);
        }
        $pdo->prepare('UPDATE users SET active = ' . sql_bool(true) . ' WHERE id = ?')->execute([$id]);
        $pdo->prepare("UPDATE employees SET status = 'active' WHERE user_id = ?")->execute([$id]);
        json_response(['success' => true]);
    }

    if ($act === 'delete') {
        validate_required($data, ['id']);
        $id = (int)$data['id'];
        if ($id === (int)$user['id']) {
            json_error('You cannot delete your own account', 400);
        }
        $existing = $pdo->prepare('SELECT id, role FROM users WHERE id = ?');
        $existing->execute([$id]);
        $row = $existing->fetch();
        if (!$row) {
            json_error('User not found', 404);
        }
        if ($row['role'] === 'admin') {
            json_error('Admin accounts cannot be permanently deleted. Deactivate instead.', 400);
        }
        // Unlink employees first (FK ON DELETE SET NULL), then remove login.
        $pdo->prepare('UPDATE employees SET user_id = NULL, status = \'inactive\' WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        json_response(['success' => true]);
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
