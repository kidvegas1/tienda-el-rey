<?php
require_once __DIR__ . '/db.php';

function auth_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'httponly'  => true,
            'secure'    => $secure,
            'samesite'  => 'Lax',
        ]);
        session_start();
    }
}

function auth_login(string $email, string $password): array|false {
    $stmt = db()->prepare('SELECT id, name, email, password_hash, role, store_id FROM users WHERE email = ? AND ' . sql_is_active() . ' LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['store_id']  = $user['store_id'];

    if (in_array($user['role'], ['manager', 'employee', 'cashier'], true)) {
        $assignedStore = (int)($user['store_id'] ?? 0);
        if ($assignedStore <= 0) {
            $_SESSION = [];
            return false;
        }
    }

    auth_ensure_admin_store_context();

    session_regenerate_id(true);

    return auth_user_payload();
}

function auth_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function auth_check(): bool {
    return isset($_SESSION['user_id']);
}

function auth_employee_payload(array $row): array {
    return [
        'id'          => (int)$row['id'],
        'name'        => $row['name'],
        'phone'       => $row['phone'] ?? null,
        'hourly_rate' => isset($row['hourly_rate']) && $row['hourly_rate'] !== null
            ? (float)$row['hourly_rate'] : null,
    ];
}

/**
 * Resolve the employees row for the logged-in user (link by user_id or name, create if store-locked).
 */
function auth_resolve_employee(bool $autoLink = false): ?array {
    if (!auth_check()) {
        return null;
    }
    $pdo = db();
    $userId = (int)$_SESSION['user_id'];
    $storeId = (int)($_SESSION['store_id'] ?? 0);
    $storeLocked = auth_is_store_locked();

    if ($userId > 0) {
        if ($storeLocked && $storeId > 0) {
            $stmt = $pdo->prepare('SELECT id, name, phone, hourly_rate FROM employees WHERE user_id = ? AND store_id = ? LIMIT 1');
            $stmt->execute([$userId, $storeId]);
        } else {
            $stmt = $pdo->prepare('SELECT id, name, phone, hourly_rate FROM employees WHERE user_id = ? LIMIT 1');
            $stmt->execute([$userId]);
        }
        $emp = $stmt->fetch();
        if ($emp) {
            return auth_employee_payload($emp);
        }
    }

    if (!$autoLink || $storeId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, name, phone, hourly_rate, user_id FROM employees WHERE store_id = ? AND name = ? LIMIT 1');
    $stmt->execute([$storeId, $_SESSION['user_name']]);
    $emp = $stmt->fetch();
    if ($emp) {
        if (empty($emp['user_id'])) {
            $pdo->prepare('UPDATE employees SET user_id = ? WHERE id = ?')->execute([$userId, $emp['id']]);
        }
        return auth_employee_payload($emp);
    }

    $pdo->prepare('INSERT INTO employees (store_id, user_id, name) VALUES (?,?,?)')
        ->execute([$storeId, $userId, $_SESSION['user_name']]);
    return [
        'id'          => sql_last_insert_id($pdo, 'employees'),
        'name'        => $_SESSION['user_name'],
        'phone'       => null,
        'hourly_rate' => null,
    ];
}

function auth_user_payload(): array {
    auth_ensure_admin_store_context();
    $payload = [
        'id'       => $_SESSION['user_id'],
        'name'     => $_SESSION['user_name'],
        'role'     => $_SESSION['user_role'],
        'store_id' => $_SESSION['store_id'],
    ];
    $employee = auth_resolve_employee(auth_is_store_locked());
    if ($employee) {
        $payload['employee'] = $employee;
    }
    return $payload;
}

function auth_user(): ?array {
    if (!auth_check()) return null;
    return auth_user_payload();
}

function auth_require(): array {
    if (!auth_check()) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }
    return auth_user();
}

function auth_require_admin(): array {
    $user = auth_require();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        exit;
    }
    return $user;
}

function auth_is_admin(): bool {
    return auth_check() && ($_SESSION['user_role'] ?? '') === 'admin';
}

function auth_is_manager(): bool {
    return auth_check() && ($_SESSION['user_role'] ?? '') === 'manager';
}

function auth_can_import_excel(): bool {
    return auth_is_admin() || auth_is_manager();
}

/** Paths that only admins may open (HTML routes without leading slash). */
function auth_admin_only_paths(): array {
    return ['stores', 'employees', 'analytics', 'metas', 'security', 'reports-center'];
}

/** Paths open to store managers and admins. */
function auth_manager_plus_paths(): array {
    return ['import', 'sales-log'];
}

/** Retired modules — blocked for all roles (including admin). */
function auth_retired_paths(): array {
    return ['events', 'plates', 'api/events', 'api/plates'];
}

/**
 * Whether the authenticated user may open this app path (e.g. /dashboard → dashboard).
 */
function auth_page_allowed(string $pageKey): bool {
    if (in_array($pageKey, auth_retired_paths(), true)) {
        return false;
    }
    if (in_array($pageKey, ['', 'login', 'index'], true)) {
        return true;
    }
    if (!auth_check()) {
        return false;
    }
    if (auth_is_admin()) {
        return true;
    }
    if (in_array($pageKey, auth_manager_plus_paths(), true)) {
        return auth_is_manager();
    }
    return !in_array($pageKey, auth_admin_only_paths(), true);
}

function auth_is_store_locked(): bool {
    if (!auth_check()) return false;
    return in_array($_SESSION['user_role'] ?? '', ['manager', 'employee', 'cashier'], true);
}

/** Cashiers/employees see only their own employee-scoped ledger and clock-in data. */
function auth_is_personal_employee_scope(): bool {
    if (!auth_check()) return false;
    return in_array($_SESSION['user_role'] ?? '', ['employee', 'cashier'], true);
}

function auth_require_store_access(int $storeId): void {
    if (auth_is_admin()) {
        return;
    }
    $mine = (int)($_SESSION['store_id'] ?? 0);
    if (!$mine || $storeId !== $mine) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied for this store']);
        exit;
    }
}

/**
 * Non-admins may only access clients that have transfers at their store,
 * or clients with no transfers yet (new records created at the store).
 */
function auth_require_client_store_access(PDO $pdo, int $clientId): void {
    if (auth_is_admin()) {
        return;
    }
    $storeId = (int)($_SESSION['store_id'] ?? 0);
    if ($storeId <= 0) {
        json_error('No store assigned to your account', 403);
    }
    $stmt = $pdo->prepare('SELECT 1 FROM transfers WHERE client_id = ? AND store_id = ? LIMIT 1');
    $stmt->execute([$clientId, $storeId]);
    if ($stmt->fetch()) {
        return;
    }
    $any = $pdo->prepare('SELECT 1 FROM transfers WHERE client_id = ? LIMIT 1');
    $any->execute([$clientId]);
    if (!$any->fetch()) {
        return;
    }
    json_error('Client not found', 404);
}

function auth_require_receiver_store_access(PDO $pdo, int $receiverId): void {
    $stmt = $pdo->prepare('SELECT client_id FROM receivers WHERE id = ? LIMIT 1');
    $stmt->execute([$receiverId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Receiver not found', 404);
    }
    auth_require_client_store_access($pdo, (int)$row['client_id']);
}

function auth_require_stored_path_access(string $storedPath): void {
    if (auth_is_admin()) {
        return;
    }
    if ($storedPath === '') {
        json_error('Access denied', 403);
    }
    // Inventory product images are shared across authenticated staff.
    $normalized = ltrim(str_replace('\\', '/', $storedPath), '/');
    if (
        str_starts_with($normalized, 'assets/uploads/inventory/')
        || str_starts_with($storedPath, 'storage://inventory/')
        || str_starts_with($normalized, 'assets/uploads/receipts/')
        || str_starts_with($storedPath, 'storage://receipts/')
    ) {
        return;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM clients WHERE sender_id_path = ? OR income_doc_path = ? LIMIT 1');
    $stmt->execute([$storedPath, $storedPath]);
    $client = $stmt->fetch();
    if ($client) {
        auth_require_client_store_access($pdo, (int)$client['id']);
        return;
    }
    $stmt = $pdo->prepare('SELECT client_id FROM receivers WHERE id_path = ? LIMIT 1');
    $stmt->execute([$storedPath]);
    $receiver = $stmt->fetch();
    if ($receiver) {
        auth_require_client_store_access($pdo, (int)$receiver['client_id']);
        return;
    }
    json_error('Access denied', 403);
}

function auth_set_store(int $storeId): void {
    $_SESSION['store_id'] = $storeId;
}

/**
 * Admins may have null store_id in DB; store-scoped APIs need a session store.
 * Default to the first active store when none is selected.
 */
function auth_ensure_admin_store_context(): void {
    if (!auth_check() || !auth_is_admin()) {
        return;
    }
    $sessionStore = $_SESSION['store_id'] ?? null;
    if ($sessionStore !== null && $sessionStore !== '' && (int)$sessionStore > 0) {
        return;
    }
    $row = db()->query('SELECT id FROM stores WHERE ' . sql_is_active() . ' ORDER BY name LIMIT 1')->fetch();
    if ($row) {
        auth_set_store((int)$row['id']);
    }
}
