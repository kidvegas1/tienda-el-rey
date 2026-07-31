<?php

require_once __DIR__ . '/reconciliation.php';
require_once __DIR__ . '/helpers.php';

/** @return list<string> */
function goals_metric_types(): array {
    return ['envios_count', 'envios_volume', 'cambio_count', 'cambio_volume'];
}

function goals_metric_is_volume(string $metricType): bool {
    return str_ends_with($metricType, '_volume');
}

function goals_validate_metric_type(string $metricType): bool {
    return in_array($metricType, goals_metric_types(), true);
}

/** @return array{from:string,to:string,from_dt:string,to_dt:string} */
function goals_period_bounds(int $year, int $month): array {
    $month = max(1, min(12, $month));
    $year = max(2000, min(2100, $year));
    $from = sprintf('%04d-%02d-01', $year, $month);
    $to = (new DateTime($from))->format('Y-m-t');

    return [
        'from' => $from,
        'to' => $to,
        'from_dt' => $from . ' 00:00:00',
        'to_dt' => $to . ' 23:59:59',
    ];
}

/**
 * Compute envíos + cambio actuals for a store/month (same domain as finances.php).
 *
 * @return array{envios_count:int,envios_volume:float,cambio_count:int,cambio_volume:float}
 */
function goals_compute_actuals(PDO $pdo, int $storeId, int $year, int $month): array {
    $bounds = goals_period_bounds($year, $month);
    $dateFrom = $bounds['from'];
    $dateTo = $bounds['to'];
    $dateFromDt = $bounds['from_dt'];
    $dateToDt = $bounds['to_dt'];

    $storeSql = store_filter_sql('store_id', $storeId);
    $storeParams = [$storeId];

    $giros = ['count' => 0, 'volume' => 0.0];
    $cambio = ['count' => 0, 'volume' => 0.0];

    $barriSql = "SELECT transaction_type, principal
     FROM barri_transactions
     WHERE transaction_date BETWEEN ? AND ?" . $storeSql;
    $barriStmt = $pdo->prepare($barriSql);
    $barriStmt->execute(array_merge([$dateFrom, $dateTo], $storeParams));

    foreach ($barriStmt->fetchAll() as $row) {
        $type = (string)($row['transaction_type'] ?? '');
        $norm = str_replace(' ', '_', strtolower(trim($type)));
        $principal = (float)($row['principal'] ?? 0);

        if (recon_is_cambio_type($type)) {
            $cambio['count']++;
            $cambio['volume'] += $principal;
        } elseif ($norm === 'giros' || $norm === 'money_transfer') {
            $giros['count']++;
            $giros['volume'] += $principal;
        }
    }

    if ($giros['count'] === 0) {
        $trSql = "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_usd),0) AS volume
         FROM transfers
         WHERE date_sent BETWEEN ? AND ?
           AND REPLACE(LOWER(COALESCE(transaction_type,'')),' ','_') IN ('giros','money_transfer')"
            . $storeSql;
        $trStmt = $pdo->prepare($trSql);
        $trStmt->execute(array_merge([$dateFromDt, $dateToDt], $storeParams));
        $tr = $trStmt->fetch() ?: [];
        $giros['count'] = (int)($tr['cnt'] ?? 0);
        $giros['volume'] = (float)($tr['volume'] ?? 0);
    }

    return [
        'envios_count' => $giros['count'],
        'envios_volume' => round($giros['volume'], 2),
        'cambio_count' => $cambio['count'],
        'cambio_volume' => round($cambio['volume'], 2),
    ];
}

function goals_build_progress(?array $goal, float $actual, string $metricType): array {
    $target = $goal ? (float)($goal['target_value'] ?? 0) : null;
    $pct = null;
    $remaining = null;

    if ($target !== null && $target > 0) {
        $pct = round(min(999.9, ($actual / $target) * 100), 1);
        $remaining = round(max(0, $target - $actual), goals_metric_is_volume($metricType) ? 2 : 0);
    }

    return [
        'id' => $goal ? (int)$goal['id'] : null,
        'metric_type' => $metricType,
        'target' => $target,
        'actual' => goals_metric_is_volume($metricType) ? round($actual, 2) : (int)$actual,
        'pct' => $pct,
        'remaining' => $remaining,
        'notes' => $goal['notes'] ?? null,
        'is_volume' => goals_metric_is_volume($metricType),
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function goals_list_with_progress(PDO $pdo, int $storeId, int $year, int $month): array {
    $stmt = $pdo->prepare(
        'SELECT id, store_id, metric_type, period_year, period_month, target_value, notes, created_by_user_id, created_at, updated_at
         FROM store_goals
         WHERE store_id = ? AND period_year = ? AND period_month = ?'
    );
    $stmt->execute([$storeId, $year, $month]);
    $rows = $stmt->fetchAll();

    $byMetric = [];
    foreach ($rows as $row) {
        $byMetric[(string)$row['metric_type']] = $row;
    }

    $actuals = goals_compute_actuals($pdo, $storeId, $year, $month);
    $metrics = [];
    foreach (goals_metric_types() as $metricType) {
        $metrics[] = goals_build_progress($byMetric[$metricType] ?? null, (float)$actuals[$metricType], $metricType);
    }

    return $metrics;
}

function goals_upsert(PDO $pdo, int $storeId, string $metricType, int $year, int $month, float $targetValue, ?string $notes, int $userId): int {
    if (!goals_validate_metric_type($metricType)) {
        throw new InvalidArgumentException('Invalid metric type');
    }
    if ($targetValue < 0) {
        throw new InvalidArgumentException('Target must be zero or greater');
    }
    if ($month < 1 || $month > 12) {
        throw new InvalidArgumentException('Invalid month');
    }

    $columns = ['store_id', 'metric_type', 'period_year', 'period_month', 'target_value', 'notes', 'created_by_user_id', 'updated_at'];
    $sql = sql_upsert(
        'store_goals',
        $columns,
        ['target_value', 'notes', 'created_by_user_id', 'updated_at'],
        ['store_id', 'metric_type', 'period_year', 'period_month']
    );

    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $storeId,
        $metricType,
        $year,
        $month,
        round($targetValue, goals_metric_is_volume($metricType) ? 2 : 0),
        $notes,
        $userId,
        $now,
    ]);

    $existing = $pdo->prepare(
        'SELECT id FROM store_goals WHERE store_id = ? AND metric_type = ? AND period_year = ? AND period_month = ? LIMIT 1'
    );
    $existing->execute([$storeId, $metricType, $year, $month]);
    $id = $existing->fetchColumn();

    return $id ? (int)$id : sql_last_insert_id($pdo, 'store_goals');
}

function goals_delete(PDO $pdo, int $goalId): bool {
    $stmt = $pdo->prepare('DELETE FROM store_goals WHERE id = ?');
    $stmt->execute([$goalId]);

    return $stmt->rowCount() > 0;
}
