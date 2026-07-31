<?php

/** @var string|null Cached PDO driver name set by db() on first connect */
$_sql_driver = null;

function sql_set_driver(string $driver): void {
    global $_sql_driver;
    $_sql_driver = $driver;
}

function db_is_pgsql(): bool {
    global $_sql_driver;

    if ($_sql_driver !== null) {
        return $_sql_driver === 'pgsql';
    }

    $databaseUrl = (string)env('DATABASE_URL', '');
    if ($databaseUrl !== '') {
        if (str_starts_with($databaseUrl, 'pgsql:') || str_starts_with($databaseUrl, 'postgresql:')) {
            return true;
        }
        if (preg_match('#^postgres(ql)?://#i', $databaseUrl)) {
            return true;
        }
    }

    return false;
}

function sql_curdate(): string {
    return db_is_pgsql() ? 'CURRENT_DATE' : 'CURDATE()';
}

function sql_now(): string {
    return 'NOW()';
}

/** Active-row filter (MySQL TINYINT vs PostgreSQL BOOLEAN). */
function sql_is_active(string $column = 'active'): string {
    return sql_is_true($column);
}

function sql_is_true(string $column): string {
    return db_is_pgsql() ? "$column IS TRUE" : "$column = 1";
}

function sql_is_false(string $column): string {
    return db_is_pgsql() ? "$column IS FALSE" : "$column = 0";
}

/** Boolean literal for inline SQL (UPDATE/INSERT without bind). */
function sql_bool(bool $value): string {
    if (db_is_pgsql()) {
        return $value ? 'TRUE' : 'FALSE';
    }
    return $value ? '1' : '0';
}

/**
 * Boolean value for PDO prepared-statement binds.
 * Always use 0/1 — PDO pgsql can send PHP false as "" which Postgres rejects
 * with: invalid input syntax for type boolean: ""
 */
function db_bool(bool $value): int {
    return $value ? 1 : 0;
}

function sql_year(string $col): string {
    return db_is_pgsql()
        ? "EXTRACT(YEAR FROM $col)::int"
        : "YEAR($col)";
}

function sql_month(string $col): string {
    return db_is_pgsql()
        ? "EXTRACT(MONTH FROM $col)::int"
        : "MONTH($col)";
}

function sql_date(string $col): string {
    return db_is_pgsql()
        ? "($col)::date"
        : "DATE($col)";
}

function sql_date_eq_today(string $col): string {
    return sql_date($col) . ' = ' . sql_curdate();
}

/** First day of the month N months before the current month (0 = current month). */
function sql_month_start(int $monthsAgo = 0): string {
    if (db_is_pgsql()) {
        if ($monthsAgo === 0) {
            return "DATE_TRUNC('month', CURRENT_DATE)::date";
        }
        return "(DATE_TRUNC('month', CURRENT_DATE) - INTERVAL '{$monthsAgo} months')::date";
    }
    if ($monthsAgo === 0) {
        return "DATE_FORMAT(CURDATE(), '%Y-%m-01')";
    }
    return "DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL {$monthsAgo} MONTH), '%Y-%m-01')";
}

function sql_date_format_ym(string $col): string {
    return db_is_pgsql()
        ? "TO_CHAR($col, 'YYYY-MM')"
        : "DATE_FORMAT($col, '%Y-%m')";
}

function sql_same_month_year(string $col): string {
    if (db_is_pgsql()) {
        return "EXTRACT(MONTH FROM $col) = EXTRACT(MONTH FROM CURRENT_DATE) AND EXTRACT(YEAR FROM $col) = EXTRACT(YEAR FROM CURRENT_DATE)";
    }
    return "MONTH($col) = MONTH(CURDATE()) AND YEAR($col) = YEAR(CURDATE())";
}

/**
 * Build INSERT ... upsert SQL (MySQL ON DUPLICATE KEY / PostgreSQL ON CONFLICT).
 *
 * @param list<string> $columns
 * @param list<string> $updateColumns columns to refresh from the inserted row
 * @param list<string> $conflictColumns unique / conflict target columns
 */
function sql_upsert(string $table, array $columns, array $updateColumns, array $conflictColumns): string {
    $colList = implode(', ', $columns);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));

    if (db_is_pgsql()) {
        $conflict = implode(', ', $conflictColumns);
        $updates = array_map(
            fn(string $col) => "$col = EXCLUDED.$col",
            $updateColumns
        );
        return "INSERT INTO $table ($colList) VALUES ($placeholders) ON CONFLICT ($conflict) DO UPDATE SET " . implode(', ', $updates);
    }

    $updates = array_map(
        fn(string $col) => "$col = VALUES($col)",
        $updateColumns
    );
    return "INSERT INTO $table ($colList) VALUES ($placeholders) ON DUPLICATE KEY UPDATE " . implode(', ', $updates);
}

/** PostgreSQL-safe last insert id (SERIAL sequences). */
function sql_last_insert_id(PDO $pdo, string $table, string $idColumn = 'id'): int {
    if (db_is_pgsql()) {
        return (int)$pdo->lastInsertId("{$table}_{$idColumn}_seq");
    }
    return (int)$pdo->lastInsertId();
}

/** Case-insensitive LIKE operator (ILIKE on Postgres). */
function sql_like_op(): string {
    return db_is_pgsql() ? 'ILIKE' : 'LIKE';
}

/**
 * Accent-folded lowercase SQL expression for fuzzy name matching.
 */
function sql_fold_text(string $col): string {
    $from = 'áéíóúüñàèìòùäëïöüçÁÉÍÓÚÜÑÀÈÌÒÙÄËÏÖÜÇ';
    $to   = 'aeiouunaeiouaeiouc' . 'aeiouunaeiouaeiouc';
    if (db_is_pgsql()) {
        return "translate(lower({$col}), '{$from}', '{$to}')";
    }
    return "LOWER({$col})";
}

/** Normalize a user search string the same way as sql_fold_text. */
function search_fold(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $map = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ];
    return strtr($s, $map);
}
