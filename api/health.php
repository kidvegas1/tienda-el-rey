<?php
/**
 * Load balancer / platform health check.
 * GET /api/health — JSON 200 when app + database are reachable.
 */
$checks = ['app' => 'ok'];
$status = 'ok';
$httpCode = 200;

try {
    db()->query('SELECT 1');
    $checks['database'] = 'ok';
} catch (Throwable $e) {
    $checks['database'] = 'error';
    $status = 'degraded';
    $httpCode = 503;
    error_log('[health] database check failed: ' . $e->getMessage());
}

json_response([
    'status'    => $status,
    'service'   => APP_NAME,
    'checks'    => $checks,
    'timestamp' => gmdate('c'),
], $httpCode);
