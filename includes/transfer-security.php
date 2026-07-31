<?php

/**
 * Transfer security alert helpers — automated risk detection for transfers.
 */

require_once __DIR__ . '/sql.php';
require_once __DIR__ . '/helpers.php';

function transfer_security_table_exists(PDO $pdo): bool
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'pgsql') {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = 'public' AND table_name = 'transfer_security_alerts'"
        );
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'transfer_security_alerts'"
    );
    $stmt->execute();
    return (bool)$stmt->fetchColumn();
}

/**
 * Insert a security alert if the table exists and no duplicate open alert
 * of the same type+client+transfer was created within the last 24 hours.
 *
 * @return int|null  Inserted row id, or null if skipped / table missing.
 */
function transfer_security_create_alert(PDO $pdo, array $fields): ?int
{
    if (!transfer_security_table_exists($pdo)) {
        return null;
    }

    $alertType  = $fields['alert_type']  ?? '';
    $clientId   = $fields['client_id']   ?? null;
    $transferId = $fields['transfer_id'] ?? null;

    $dupParams = [$alertType];
    $dupClientSql = $clientId !== null ? 'AND client_id = ?' : 'AND client_id IS NULL';
    if ($clientId !== null) {
        $dupParams[] = $clientId;
    }
    $dupTransferSql = $transferId !== null ? 'AND transfer_id = ?' : 'AND transfer_id IS NULL';
    if ($transferId !== null) {
        $dupParams[] = $transferId;
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM transfer_security_alerts
         WHERE alert_type = ? $dupClientSql $dupTransferSql
           AND status = 'open'
           AND detected_at >= " . (db_is_pgsql() ? "NOW() - INTERVAL '24 hours'" : "DATE_SUB(NOW(), INTERVAL 24 HOUR)") . "
         LIMIT 1"
    );
    $stmt->execute($dupParams);
    if ($stmt->fetchColumn()) {
        return null;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO transfer_security_alerts
            (alert_type, severity, client_id, store_id, transfer_id, title, details)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $alertType,
        $fields['severity']  ?? 'medium',
        $clientId,
        $fields['store_id']  ?? null,
        $transferId,
        $fields['title']     ?? '',
        $fields['details']   ?? '',
    ]);
    return sql_last_insert_id($pdo, 'transfer_security_alerts');
}

/**
 * Scan a transfer against all security rules and create alerts as needed.
 *
 * @return array  List of alert rows created (may be empty).
 */
function transfer_security_scan_transfer(PDO $pdo, int $transferId): array
{
    if (!transfer_security_table_exists($pdo)) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT t.*, c.name AS client_name, c.monthly_limit
         FROM transfers t
         JOIN clients c ON c.id = t.client_id
         WHERE t.id = ?'
    );
    $stmt->execute([$transferId]);
    $transfer = $stmt->fetch();
    if (!$transfer) {
        return [];
    }

    $clientId   = (int)$transfer['client_id'];
    $storeId    = (int)$transfer['store_id'];
    $amount     = (float)$transfer['amount_usd'];
    $dateSent   = $transfer['date_sent'];
    $dateSentDate = date('Y-m-d', strtotime($dateSent));
    $monthlyLimit = (float)$transfer['monthly_limit'];
    $company    = (string)($transfer['company'] ?? '');

    $alerts = [];

    // Rule 1: over_limit — month usage at this store exceeds client monthly_limit
    $monthStart = date('Y-m-01', strtotime($dateSent));
    $monthEnd   = date('Y-m-t', strtotime($dateSent));
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(amount_usd), 0) FROM transfers
         WHERE client_id = ? AND store_id = ?
           AND ' . sql_date('date_sent') . ' >= ? AND ' . sql_date('date_sent') . ' <= ?'
    );
    $stmt->execute([$clientId, $storeId, $monthStart, $monthEnd]);
    $monthUsage = (float)$stmt->fetchColumn();
    if ($monthlyLimit > 0 && $monthUsage > $monthlyLimit) {
        $id = transfer_security_create_alert($pdo, [
            'alert_type'  => 'over_limit',
            'severity'    => 'high',
            'client_id'   => $clientId,
            'store_id'    => $storeId,
            'transfer_id' => $transferId,
            'title'       => 'Monthly limit exceeded',
            'details'     => "Client \"{$transfer['client_name']}\" month usage \${$monthUsage} exceeds limit \${$monthlyLimit} at store #{$storeId}.",
        ]);
        if ($id) {
            $alerts[] = $id;
        }
    }

    // Rule 2: multi_store_same_day — same client has transfers at 2+ stores on same date
    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT store_id) FROM transfers
         WHERE client_id = ? AND ' . sql_date('date_sent') . ' = ?'
    );
    $stmt->execute([$clientId, $dateSentDate]);
    $storeCount = (int)$stmt->fetchColumn();
    if ($storeCount >= 2) {
        $id = transfer_security_create_alert($pdo, [
            'alert_type'  => 'multi_store_same_day',
            'severity'    => 'high',
            'client_id'   => $clientId,
            'store_id'    => $storeId,
            'transfer_id' => $transferId,
            'title'       => 'Multi-store same-day transfers',
            'details'     => "Client \"{$transfer['client_name']}\" sent transfers at {$storeCount} different stores on {$dateSentDate}.",
        ]);
        if ($id) {
            $alerts[] = $id;
        }
    }

    // Rule 3: frequency_spike — 4+ transfers by same client on same day
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM transfers
         WHERE client_id = ? AND ' . sql_date('date_sent') . ' = ?'
    );
    $stmt->execute([$clientId, $dateSentDate]);
    $dayCount = (int)$stmt->fetchColumn();
    if ($dayCount >= 4) {
        $id = transfer_security_create_alert($pdo, [
            'alert_type'  => 'frequency_spike',
            'severity'    => 'medium',
            'client_id'   => $clientId,
            'store_id'    => $storeId,
            'transfer_id' => $transferId,
            'title'       => 'High transfer frequency',
            'details'     => "Client \"{$transfer['client_name']}\" has {$dayCount} transfers on {$dateSentDate}.",
        ]);
        if ($id) {
            $alerts[] = $id;
        }
    }

    // Rule 4: near_limit_structuring — 3+ transfers same day AND day total >= 90% of monthly limit
    if ($dayCount >= 3 && $monthlyLimit > 0) {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount_usd), 0) FROM transfers
             WHERE client_id = ? AND ' . sql_date('date_sent') . ' = ?'
        );
        $stmt->execute([$clientId, $dateSentDate]);
        $dayTotal = (float)$stmt->fetchColumn();
        if ($dayTotal >= 0.9 * $monthlyLimit) {
            $id = transfer_security_create_alert($pdo, [
                'alert_type'  => 'near_limit_structuring',
                'severity'    => 'high',
                'client_id'   => $clientId,
                'store_id'    => $storeId,
                'transfer_id' => $transferId,
                'title'       => 'Possible structuring detected',
                'details'     => "Client \"{$transfer['client_name']}\" sent {$dayCount} transfers totalling \${$dayTotal} on {$dateSentDate} (limit \${$monthlyLimit}).",
            ]);
            if ($id) {
                $alerts[] = $id;
            }
        }
    }

    // Rule 5: unusual_amount — amount >= max(500, 3 * avg prior transfers) when client has >= 3 prior
    $stmt = $pdo->prepare(
        'SELECT COUNT(*), COALESCE(AVG(amount_usd), 0) FROM transfers
         WHERE client_id = ? AND id != ?'
    );
    $stmt->execute([$clientId, $transferId]);
    $row = $stmt->fetch();
    $priorCount = (int)$row[0];
    $priorAvg   = (float)$row[1];
    if ($priorCount >= 3) {
        $threshold = max(500, 3 * $priorAvg);
        if ($amount >= $threshold) {
            $id = transfer_security_create_alert($pdo, [
                'alert_type'  => 'unusual_amount',
                'severity'    => 'medium',
                'client_id'   => $clientId,
                'store_id'    => $storeId,
                'transfer_id' => $transferId,
                'title'       => 'Unusual transfer amount',
                'details'     => "Transfer \${$amount} is >= 3x client avg (\${$priorAvg}) with {$priorCount} prior transfers.",
            ]);
            if ($id) {
                $alerts[] = $id;
            }
        }
    }

    // Rule 6: company_flagged — active company flag matches transfer company
    if ($company !== '') {
        require_once __DIR__ . '/company-flags.php';
        if (company_flags_table_exists($pdo)) {
            $key = company_flag_normalize_key($company);
            $flag = company_flag_get_active($pdo, $key);
            if ($flag) {
                $id = transfer_security_create_alert($pdo, [
                    'alert_type'  => 'company_flagged',
                    'severity'    => 'low',
                    'client_id'   => $clientId,
                    'store_id'    => $storeId,
                    'transfer_id' => $transferId,
                    'title'       => 'Flagged company used',
                    'details'     => "Transfer uses flagged company \"{$company}\": {$flag['reason']}",
                ]);
                if ($id) {
                    $alerts[] = $id;
                }
            }
        }
    }

    // Fetch full alert rows for returned ids
    if ($alerts === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($alerts), '?'));
    $stmt = $pdo->prepare(
        "SELECT * FROM transfer_security_alerts WHERE id IN ($placeholders) ORDER BY id"
    );
    $stmt->execute($alerts);
    return $stmt->fetchAll() ?: [];
}

/**
 * List security alerts with optional status and store filters.
 */
function transfer_security_list(
    PDO $pdo,
    ?string $status = 'open',
    ?int $storeId = null,
    int $limit = 100
): array {
    if (!transfer_security_table_exists($pdo)) {
        return [];
    }

    $where = '1=1';
    $params = [];

    if ($status !== null && $status !== '' && $status !== 'all') {
        $where .= ' AND tsa.status = ?';
        $params[] = $status;
    }

    $storeSql = store_filter_sql('tsa.store_id', $storeId);
    if ($storeId) {
        $params[] = $storeId;
    }

    $params[] = $limit;

    $stmt = $pdo->prepare(
        "SELECT tsa.*, c.name AS client_name, s.name AS store_name, u.name AS resolved_by_name
         FROM transfer_security_alerts tsa
         LEFT JOIN clients c ON c.id = tsa.client_id
         LEFT JOIN stores s ON s.id = tsa.store_id
         LEFT JOIN users u ON u.id = tsa.resolved_by_user_id
         WHERE {$where}{$storeSql}
         ORDER BY tsa.detected_at DESC
         LIMIT ?"
    );
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

/**
 * Resolve or dismiss a security alert.
 */
function transfer_security_resolve(PDO $pdo, int $alertId, int $userId, string $status, string $notes = ''): void
{
    if (!transfer_security_table_exists($pdo)) {
        require_once __DIR__ . '/security.php';
        json_error('Security alerts table not available', 503);
    }

    if (!in_array($status, ['resolved', 'dismissed'], true)) {
        require_once __DIR__ . '/security.php';
        json_error('Status must be resolved or dismissed', 400);
    }

    $stmt = $pdo->prepare(
        'UPDATE transfer_security_alerts
         SET status = ?, resolved_at = ' . sql_now() . ', resolved_by_user_id = ?, resolution_notes = ?
         WHERE id = ? AND status = \'open\''
    );
    $stmt->execute([$status, $userId, $notes, $alertId]);
    if ($stmt->rowCount() < 1) {
        require_once __DIR__ . '/security.php';
        json_error('Alert not found or already resolved', 404);
    }
}

/**
 * Open alert counts grouped by severity.
 *
 * @return array{low:int,medium:int,high:int}
 */
function transfer_security_open_count_by_severity(PDO $pdo): array
{
    $counts = ['low' => 0, 'medium' => 0, 'high' => 0];
    if (!transfer_security_table_exists($pdo)) {
        return $counts;
    }

    $stmt = $pdo->query(
        "SELECT severity, COUNT(*) AS cnt
         FROM transfer_security_alerts
         WHERE status = 'open'
         GROUP BY severity"
    );
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $sev = $row['severity'] ?? '';
        if (isset($counts[$sev])) {
            $counts[$sev] = (int)$row['cnt'];
        }
    }
    return $counts;
}

/**
 * Open alerts for a single client (newest first).
 */
function transfer_security_open_for_client(PDO $pdo, int $clientId, int $limit = 20): array
{
    if (!transfer_security_table_exists($pdo) || $clientId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT tsa.*, s.name AS store_name
         FROM transfer_security_alerts tsa
         LEFT JOIN stores s ON s.id = tsa.store_id
         WHERE tsa.client_id = ? AND tsa.status = 'open'
         ORDER BY tsa.detected_at DESC
         LIMIT ?"
    );
    $stmt->execute([$clientId, $limit]);
    return $stmt->fetchAll() ?: [];
}
