<?php
$method = get_method();

if ($method === 'POST') {
    $data = get_json_body();
    $action = $data['action'] ?? '';

    if ($action === 'login') {
        validate_required($data, ['email', 'password']);
        $user = auth_login($data['email'], $data['password']);
        if (!$user) {
            json_error('Invalid email or password.', 401);
        }
        json_response([
            'success' => true,
            'user'    => $user,
            'csrf'    => csrf_token(),
        ]);
    }

    if ($action === 'logout') {
        csrf_verify();
        auth_logout();
        json_response(['success' => true]);
    }

    if ($action === 'switch_store') {
        csrf_verify();
        auth_require_admin();
        validate_required($data, ['store_id']);
        $storeId = (int)$data['store_id'];
        $exists = db()->prepare('SELECT id FROM stores WHERE id = ? AND ' . sql_is_active());
        $exists->execute([$storeId]);
        if (!$exists->fetch()) {
            json_error('Store not found', 404);
        }
        auth_set_store($storeId);
        json_response(['success' => true, 'store_id' => $storeId]);
    }

    if ($action === 'change_password') {
        csrf_verify();
        $user = auth_require();
        validate_required($data, ['current_password', 'new_password', 'confirm_password']);
        $current = (string)$data['current_password'];
        $new = (string)$data['new_password'];
        $confirm = (string)$data['confirm_password'];
        if (strlen($new) < 8) {
            json_error('New password must be at least 8 characters', 400);
        }
        if ($new !== $confirm) {
            json_error('New password and confirmation do not match', 400);
        }
        if ($current === $new) {
            json_error('New password must be different from the current password', 400);
        }
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$user['id']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($current, $row['password_hash'])) {
            json_error('Current password is incorrect', 400);
        }
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, (int)$user['id']]);
        json_response(['success' => true]);
    }

    json_error('Unknown action.', 400);
}

if ($method === 'GET') {
    if (!auth_check()) {
        json_response(['authenticated' => false]);
    }
    $user = auth_user();
    if ($user['role'] === 'admin') {
        $stores = db()->query(
            'SELECT id, name, address, phone, barri_agency_number, barri_operator_number, viamericas_agency_number, intercambio_agency_number, intermex_agency_number FROM stores WHERE ' . sql_is_active() . ' ORDER BY name'
        )->fetchAll();
    } else {
        $userStoreId = (int)($user['store_id'] ?? 0);
        if ($userStoreId <= 0) {
            json_error('No store assigned to your account', 403);
        }
        $stmt = db()->prepare('SELECT id, name, address, phone FROM stores WHERE id = ? AND ' . sql_is_active());
        $stmt->execute([$userStoreId]);
        $row = $stmt->fetch();
        $stores = $row ? [$row] : [];
    }
    json_response([
        'authenticated' => true,
        'user'          => $user,
        'stores'        => $stores,
        'csrf'          => csrf_token(),
    ]);
}

json_error('Method not allowed', 405);
