<?php

/**
 * Shared transaction analytics query helpers (no HTTP; pure data).
 */

require_once __DIR__ . '/sql.php';
require_once __DIR__ . '/helpers.php';

/**
 * Summary stats for a date range: count, principal, fees, unique_clients, companies.
 */
function txn_analytics_summary(PDO $pdo, ?int $storeId, string $dateFrom, string $dateTo): array
{
    $storeSql = store_filter_sql('store_id', $storeId);
    $params = [];
    if ($storeId) {
        $params[] = $storeId;
    }
    $params[] = $dateFrom;
    $params[] = $dateTo;

    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS count,
            COALESCE(SUM(amount_usd), 0) AS principal,
            COALESCE(SUM(fee), 0) AS fees,
            COUNT(DISTINCT client_id) AS unique_clients,
            COUNT(DISTINCT company) AS companies
         FROM transfers
         WHERE 1=1{$storeSql}
           AND " . sql_date('date_sent') . " >= ?
           AND " . sql_date('date_sent') . " <= ?"
    );
    $stmt->execute($params);
    $row = $stmt->fetch();
    return [
        'count'          => (int)($row['count'] ?? 0),
        'principal'      => (float)($row['principal'] ?? 0),
        'fees'           => (float)($row['fees'] ?? 0),
        'unique_clients' => (int)($row['unique_clients'] ?? 0),
        'companies'      => (int)($row['companies'] ?? 0),
    ];
}

/**
 * Breakdown by company for a date range.
 */
function txn_analytics_by_company(PDO $pdo, ?int $storeId, string $dateFrom, string $dateTo): array
{
    $storeSql = store_filter_sql('store_id', $storeId);
    $params = [];
    if ($storeId) {
        $params[] = $storeId;
    }
    $params[] = $dateFrom;
    $params[] = $dateTo;

    $stmt = $pdo->prepare(
        "SELECT
            company,
            COUNT(*) AS count,
            COALESCE(SUM(amount_usd), 0) AS principal,
            COALESCE(SUM(fee), 0) AS fees,
            COUNT(DISTINCT client_id) AS unique_clients
         FROM transfers
         WHERE 1=1{$storeSql}
           AND " . sql_date('date_sent') . " >= ?
           AND " . sql_date('date_sent') . " <= ?
         GROUP BY company
         ORDER BY principal DESC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

/**
 * Monthly breakdown for a given year (defaults to current year).
 */
function txn_analytics_by_month(PDO $pdo, ?int $storeId, ?int $year = null): array
{
    $year = $year ?? (int)date('Y');

    $storeSql = store_filter_sql('store_id', $storeId);
    $params = [];
    if ($storeId) {
        $params[] = $storeId;
    }
    $params[] = $year;

    $stmt = $pdo->prepare(
        "SELECT
            " . sql_month('date_sent') . " AS month,
            COUNT(*) AS count,
            COALESCE(SUM(amount_usd), 0) AS principal,
            COALESCE(SUM(fee), 0) AS fees,
            COUNT(DISTINCT client_id) AS unique_clients
         FROM transfers
         WHERE 1=1{$storeSql}
           AND " . sql_year('date_sent') . " = ?
         GROUP BY " . sql_month('date_sent') . "
         ORDER BY month"
    );
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

/**
 * Pattern analytics for a date range.
 *
 * Returns: avg_amount, median (approximated as avg), top_companies,
 * multi_store_clients_count, high_frequency_clients, over_limit_clients_count.
 */
function txn_analytics_patterns(PDO $pdo, ?int $storeId, string $dateFrom, string $dateTo): array
{
    $storeSql = store_filter_sql('t.store_id', $storeId);
    $params = [];
    if ($storeId) {
        $params[] = $storeId;
    }
    $params[] = $dateFrom;
    $params[] = $dateTo;

    $dateFilter = sql_date('t.date_sent') . " >= ? AND " . sql_date('t.date_sent') . " <= ?";

    // avg_amount
    $stmt = $pdo->prepare(
        "SELECT COALESCE(AVG(t.amount_usd), 0) AS avg_amount
         FROM transfers t
         WHERE 1=1{$storeSql} AND {$dateFilter}"
    );
    $stmt->execute($params);
    $avgAmount = (float)$stmt->fetchColumn();

    // top_companies (top 10 by volume)
    $stmt = $pdo->prepare(
        "SELECT t.company, COUNT(*) AS count, COALESCE(SUM(t.amount_usd), 0) AS principal
         FROM transfers t
         WHERE 1=1{$storeSql} AND {$dateFilter}
         GROUP BY t.company
         ORDER BY principal DESC
         LIMIT 10"
    );
    $stmt->execute($params);
    $topCompanies = $stmt->fetchAll() ?: [];

    // multi_store_clients_count — clients with transfers at 2+ distinct stores in range
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM (
            SELECT t.client_id
            FROM transfers t
            WHERE 1=1{$storeSql} AND {$dateFilter}
            GROUP BY t.client_id
            HAVING COUNT(DISTINCT t.store_id) >= 2
         ) sub"
    );
    $stmt->execute($params);
    $multiStoreCount = (int)$stmt->fetchColumn();

    // high_frequency_clients — 4+ txns on any single day in range (top 20)
    $stmt = $pdo->prepare(
        "SELECT t.client_id, c.name AS client_name,
                " . sql_date('t.date_sent') . " AS txn_date,
                COUNT(*) AS day_count
         FROM transfers t
         JOIN clients c ON c.id = t.client_id
         WHERE 1=1{$storeSql} AND {$dateFilter}
         GROUP BY t.client_id, c.name, " . sql_date('t.date_sent') . "
         HAVING COUNT(*) >= 4
         ORDER BY day_count DESC
         LIMIT 20"
    );
    $stmt->execute($params);
    $highFrequencyClients = $stmt->fetchAll() ?: [];

    // over_limit_clients_count — clients whose total in range exceeds their monthly_limit
    // Uses per-month comparison: any month in range where usage > limit counts
    $monthCol = sql_date_format_ym('t.date_sent');
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT sub.client_id) FROM (
            SELECT t.client_id, {$monthCol} AS ym, SUM(t.amount_usd) AS month_total
            FROM transfers t
            JOIN clients c ON c.id = t.client_id
            WHERE 1=1{$storeSql} AND {$dateFilter}
            GROUP BY t.client_id, {$monthCol}
            HAVING SUM(t.amount_usd) > MAX(c.monthly_limit) AND MAX(c.monthly_limit) > 0
         ) sub"
    );
    $stmt->execute($params);
    $overLimitCount = (int)$stmt->fetchColumn();

    return [
        'avg_amount'                => round($avgAmount, 2),
        'median'                    => round($avgAmount, 2),
        'top_companies'             => $topCompanies,
        'multi_store_clients_count' => $multiStoreCount,
        'high_frequency_clients'    => $highFrequencyClients,
        'over_limit_clients_count'  => $overLimitCount,
    ];
}
