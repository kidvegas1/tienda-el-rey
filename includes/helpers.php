<?php

require_once __DIR__ . '/storage.php';

/**
 * Redirect Render/default hostnames to the canonical APP_URL domain.
 */
function app_enforce_canonical_host(): void {
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
        return;
    }
    if (env('CANONICAL_REDIRECT', '1') !== '1') {
        return;
    }

    $canonicalHost = parse_url(APP_URL, PHP_URL_HOST);
    if (!$canonicalHost) {
        return;
    }

    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || $host === strtolower($canonicalHost)) {
        return;
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'GET' && $method !== 'HEAD') {
        return;
    }

    $scheme = parse_url(APP_URL, PHP_URL_SCHEME) ?: 'https';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: ' . $scheme . '://' . $canonicalHost . $uri, true, 301);
    exit;
}

function money(float|string|null $amount): string {
    return number_format((float)($amount ?? 0), 2, '.', ',');
}

function current_store_id(): int {
    if (auth_is_admin()) {
        return (int)($_SESSION['store_id'] ?? 0);
    }
    $id = (int)($_SESSION['store_id'] ?? 0);
    if ($id <= 0) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'No store assigned to your account']);
        exit;
    }
    return $id;
}

/**
 * Resolve store context for API reads/writes.
 * Non-admin users are always locked to their assigned store.
 */
function resolve_store_id(?int $requested = null): int {
    if (auth_is_admin()) {
        if ($requested !== null && $requested > 0) {
            return $requested;
        }
        $session = (int)($_SESSION['store_id'] ?? 0);
        if ($session <= 0) {
            auth_ensure_admin_store_context();
            $session = (int)($_SESSION['store_id'] ?? 0);
        }
        if ($session > 0) {
            return $session;
        }
        json_error('Select a store context', 400);
    }

    $mine = (int)($_SESSION['store_id'] ?? 0);
    if ($mine <= 0) {
        json_error('No store assigned to your account', 403);
    }
    if ($requested !== null && $requested > 0 && $requested !== $mine) {
        json_error('Access denied for this store', 403);
    }
    return $mine;
}

/**
 * Admin may omit store (null = all stores). Non-admin always gets their store id.
 */
function resolve_store_filter(?int $requested = null): ?int {
    if (auth_is_admin()) {
        if ($requested !== null && $requested > 0) {
            return $requested;
        }
        // Admin aggregate reads default to all stores unless ?store_id= is passed.
        return null;
    }
    return resolve_store_id($requested);
}

/** Build optional store_id SQL fragment for admin all-store reads. Uses ? placeholder — bind store id in params when non-null. */
function store_filter_sql(string $column = 'store_id', ?int $storeId = null, string $prefix = ' AND '): string {
    return $storeId ? $prefix . $column . ' = ?' : '';
}

function paginate(int $total, int $perPage = 50): array {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;
    $pages = (int)ceil($total / $perPage);
    return [
        'page'    => $page,
        'offset'  => $offset,
        'limit'   => $perPage,
        'total'   => $total,
        'pages'   => $pages,
    ];
}

function upload_file(array $file, string $subdir = ''): string|false {
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > MAX_UPLOAD_SIZE) return false;

    $subdir = $subdir ? rtrim($subdir, '/') : '';
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $name = uniqid('', true) . ($ext !== '' ? '.' . $ext : '');

    if (storage_enabled()) {
        $bucket = storage_bucket_for_subdir($subdir);
        $objectPath = ($subdir !== '' ? $subdir . '/' : '') . $name;
        try {
            return storage_upload($file['tmp_name'], $bucket, $objectPath, (string)($file['name'] ?? ''));
        } catch (Throwable $e) {
            error_log('[storage] upload failed, using local disk fallback: ' . $e->getMessage());
        }
    }

    $dir = UPLOAD_DIR . ($subdir !== '' ? $subdir . '/' : '');
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $path = $dir . $name;
    // move_uploaded_file fails after GD rewrite of tmp; fall back to copy
    if (!@move_uploaded_file($file['tmp_name'], $path)) {
        if (!@copy($file['tmp_name'], $path)) {
            return false;
        }
        @unlink($file['tmp_name']);
    }

    return 'assets/uploads/' . ($subdir !== '' ? $subdir . '/' : '') . $name;
}

function date_format_display(string|null $date): string {
    if (!$date) return '—';
    return date('M d, Y', strtotime($date));
}

function datetime_format_display(string|null $dt): string {
    if (!$dt) return '—';
    return date('M d, Y g:i A', strtotime($dt));
}
