<?php

$envFile = __DIR__ . '/.env';
if (!is_file($envFile)) {
    $envFile = __DIR__ . '/env.config';
}
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val, " \t\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$val");
        }
    }
}

function env(string $key, mixed $default = null): mixed {
    $v = getenv($key);
    return $v !== false ? $v : $default;
}

define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'tienda_el_rey'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

define('SUPABASE_URL', env('SUPABASE_URL', ''));
define('SUPABASE_ANON_KEY', env('SUPABASE_ANON_KEY', ''));
define('SUPABASE_SERVICE_ROLE_KEY', env('SUPABASE_SERVICE_ROLE_KEY', ''));

define('GEMINI_API_KEY', env('GEMINI_API_KEY', ''));
define('GEMINI_MODEL', env('GEMINI_MODEL', 'gemini-2.5-flash'));

$defaultTaxRate = (float) env('GLOBAL_TAX_RATE', 8.25);
define('DEFAULT_TAX_RATE', $defaultTaxRate >= 0 && $defaultTaxRate <= 100 ? $defaultTaxRate : 8.25);

define('SESSION_SECRET', env('SESSION_SECRET', ''));

define('APP_NAME', env('APP_NAME', 'Tienda Hispana El Rey'));
define('APP_URL', env('APP_URL', 'http://localhost:8080'));
define('UPLOAD_DIR', __DIR__ . '/assets/uploads/');
define('MAX_UPLOAD_SIZE', (int)env('MAX_UPLOAD_SIZE', 10 * 1024 * 1024));

define('SESSION_LIFETIME', (int)env('SESSION_LIFETIME', 86400));
define('CSRF_TOKEN_NAME', '_csrf_token');

date_default_timezone_set('America/Chicago');

if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}
