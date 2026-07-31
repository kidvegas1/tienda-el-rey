<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/sql.php';

/**
 * Build a PDO DSN from DATABASE_URL (postgresql:// or postgres://) or return null.
 */
function db_pdo_from_database_url(string $databaseUrl): ?PDO {
    if (!preg_match('#^postgres(ql)?://#i', $databaseUrl)) {
        return null;
    }

    $parts = parse_url($databaseUrl);
    if ($parts === false || empty($parts['host'])) {
        throw new RuntimeException('Invalid DATABASE_URL');
    }

    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    $sslmode = $query['sslmode'] ?? 'require';
    $port = $parts['port'] ?? 5432;
    $dbname = ltrim($parts['path'] ?? '/postgres', '/');
    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
        $parts['host'],
        (int)$port,
        $dbname,
        $sslmode
    );

    $user = $parts['user'] ?? null;
    $pass = $parts['pass'] ?? null;

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $databaseUrl = env('DATABASE_URL');
        if ($databaseUrl) {
            $pdo = db_pdo_from_database_url($databaseUrl);
            if ($pdo === null) {
                throw new RuntimeException('DATABASE_URL must be a postgres:// or postgresql:// URI');
            }
        } else {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        sql_set_driver($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }
    return $pdo;
}
