<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/helpers.php';

auth_start();
app_enforce_canonical_host();

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// HTML shells and local uploads are never public files. Canonical page routes
// below enforce authentication/authorization; uploaded files use api/files.
$lowerUri = strtolower($uri);
$directPageRequest = str_starts_with($lowerUri, '/pages/')
    && str_ends_with($lowerUri, '.html');
$directUploadRequest = $lowerUri === '/assets/uploads'
    || str_starts_with($lowerUri, '/assets/uploads/');
if ($directPageRequest || $directUploadRequest) {
    header('Cache-Control: no-store');
    http_response_code(404);
    exit;
}

// Serve static files directly when using PHP built-in server
if (php_sapi_name() === 'cli-server') {
    $staticFile = __DIR__ . $uri;
    if ($uri !== '/' && is_file($staticFile)) {
        return false;
    }
}

$path = '/' . trim($uri, '/');

if (in_array(trim($path, '/'), auth_retired_paths(), true)) {
    header('Location: /inventory', true, 302);
    exit;
}

// Legacy ledger URL
if ($path === '/suly-ledger') {
    header('Location: /libro-interno', true, 301);
    exit;
}

if (str_starts_with($path, '/api/')) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    ob_start();

    header('Content-Type: application/json; charset=utf-8');

    $apiSegment = trim(substr($path, 5), '/');
    $apiFile = __DIR__ . '/api/' . basename($apiSegment) . '.php';
    if (file_exists($apiFile)) {
        try {
            require $apiFile;
        } catch (\Throwable $e) {
            ob_end_clean();
            error_log('[API error] ' . $path . ': ' . $e->getMessage());
            json_error('Internal server error', 500);
        }
    } else {
        ob_end_clean();
        json_error('Endpoint not found', 404);
    }
    $leaked = ob_get_clean();
    if ($leaked && !headers_sent()) {
        error_log('[API leak] ' . $path . ': ' . substr($leaked, 0, 500));
    }
    exit;
}

$pageMap = [
    '/'            => 'pages/home.html',
    '/inicio'      => 'pages/home.html',
    '/login'       => 'pages/login.html',
    '/dashboard'   => 'pages/dashboard.html',
    '/caja'        => 'pages/caja.html',
    '/clients'     => 'pages/clients.html',
    '/libro-interno' => 'pages/libro-interno.html',
    '/schedule'    => 'pages/schedule.html',
    '/employees'   => 'pages/employees.html',
    '/statistics'  => 'pages/statistics.html',
    '/accounting'  => 'pages/accounting.html',
    '/finances'    => 'pages/finances.html',
    '/receipts'    => 'pages/receipts.html',
    '/company-verification' => 'pages/company-verification.html',
    '/inventory'   => 'pages/inventory.html',
    '/sales-log'   => 'pages/sales-log.html',
    '/import'      => 'pages/import.html',
    '/reports'        => 'pages/reports.html',
    '/reports-center' => 'pages/reports-center.html',
    '/analytics'      => 'pages/analytics.html',
    '/metas'          => 'pages/metas.html',
    '/security'       => 'pages/security.html',
    '/stores'         => 'pages/stores.html',
    '/precios-cambio' => 'pages/precios-cambio.html',
    '/exchange-prices'=> 'pages/precios-cambio.html',
    '/tienda'         => 'pages/catalog.html',
    '/productos'      => 'pages/catalog.html',
];

if (isset($pageMap[$path])) {
    $pageKey = trim($path, '/') ?: 'home';
    $publicPages = ['home', 'inicio', 'login', 'tienda', 'productos'];
    if (!in_array($pageKey, $publicPages, true) && !auth_check()) {
        header('Location: /login', true, 302);
        exit;
    }
    if (!in_array($pageKey, $publicPages, true) && !auth_page_allowed($pageKey)) {
        header('Location: /dashboard', true, 302);
        exit;
    }
    $file = __DIR__ . '/' . $pageMap[$path];
    if (file_exists($file)) {
        readfile($file);
        exit;
    }
}

http_response_code(404);
echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Página no encontrada — Tienda Hispana El Rey</title></head><body style="font-family:system-ui,sans-serif;margin:0;min-height:100vh;display:grid;place-items:center;background:#edf8fc;color:#4b3327"><main style="text-align:center;padding:2rem"><p style="font-weight:700;color:#277c9b">Error 404</p><h1>Página no encontrada</h1><p>La dirección que buscas no existe.</p><a href="/" style="color:#6f442f;font-weight:700">Volver al inicio</a></main></body></html>';
