<?php

/**
 * Manual admin-managed company risk flags (global, not session-scoped).
 */

require_once __DIR__ . '/sql.php';
require_once __DIR__ . '/security.php';

function company_flag_normalize_key(string $label): string {
    $label = trim($label);
    if ($label === '') {
        return '';
    }
    $label = preg_replace('/\s+/u', ' ', $label) ?? $label;
    return mb_strtoupper($label, 'UTF-8');
}

function company_flags_table_exists(PDO $pdo): bool {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'pgsql') {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = 'public' AND table_name = 'company_flags'"
        );
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'company_flags'"
    );
    $stmt->execute();
    return (bool)$stmt->fetchColumn();
}

function company_flags_list_active(PDO $pdo): array {
    if (!company_flags_table_exists($pdo)) {
        return [];
    }

    $stmt = $pdo->query(
        "SELECT cf.*, u.name AS flagged_by_name
         FROM company_flags cf
         LEFT JOIN users u ON u.id = cf.flagged_by_user_id
         WHERE cf.is_active = " . sql_bool(true) . "
         ORDER BY cf.flagged_at DESC, cf.company_label ASC"
    );
    return $stmt->fetchAll() ?: [];
}

function company_flag_get_active(PDO $pdo, string $companyKey): ?array {
    if ($companyKey === '' || !company_flags_table_exists($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT cf.*, u.name AS flagged_by_name
         FROM company_flags cf
         LEFT JOIN users u ON u.id = cf.flagged_by_user_id
         WHERE cf.company_key = ? AND cf.is_active = " . sql_bool(true) . "
         LIMIT 1"
    );
    $stmt->execute([$companyKey]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function company_flags_map_for_labels(PDO $pdo, array $labels): array {
    if (!company_flags_table_exists($pdo) || $labels === []) {
        return [];
    }

    $keys = [];
    foreach ($labels as $label) {
        $key = company_flag_normalize_key((string)$label);
        if ($key !== '') {
            $keys[$key] = true;
        }
    }
    if ($keys === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare(
        "SELECT cf.*, u.name AS flagged_by_name
         FROM company_flags cf
         LEFT JOIN users u ON u.id = cf.flagged_by_user_id
         WHERE cf.is_active = " . sql_bool(true) . " AND cf.company_key IN ($placeholders)"
    );
    $stmt->execute(array_keys($keys));

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[$row['company_key']] = $row;
    }
    return $map;
}

function company_flag_set(PDO $pdo, string $label, string $reason, int $userId): array {
    $label = trim($label);
    $reason = trim($reason);
    if ($label === '') {
        json_error('Company name is required', 400);
    }
    if ($reason === '') {
        json_error('Reason is required', 400);
    }
    if (!company_flags_table_exists($pdo)) {
        json_error('Company flags are not available yet', 503);
    }

    $key = company_flag_normalize_key($label);
    $existing = company_flag_get_active($pdo, $key);
    if ($existing) {
        $stmt = $pdo->prepare(
            'UPDATE company_flags
             SET company_label = ?, reason = ?, flagged_by_user_id = ?, flagged_at = ' . sql_now() . '
             WHERE id = ?'
        );
        $stmt->execute([$label, sanitize($reason), $userId, (int)$existing['id']]);
        if ($stmt->rowCount() < 1) {
            json_error('Flag not updated', 404);
        }
        return company_flag_get_active($pdo, $key) ?? $existing;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO company_flags (company_key, company_label, reason, flagged_by_user_id, is_active)
         VALUES (?, ?, ?, ?, ' . sql_bool(true) . ')'
    );
    $stmt->execute([$key, $label, sanitize($reason), $userId]);
    $created = company_flag_get_active($pdo, $key);
    if (!$created) {
        json_error('Flag not created', 500);
    }
    return $created;
}

function company_flag_clear(PDO $pdo, string $companyKey, int $userId): void {
    $companyKey = company_flag_normalize_key($companyKey);
    if ($companyKey === '') {
        json_error('Company key is required', 400);
    }
    if (!company_flags_table_exists($pdo)) {
        json_error('Company flags are not available yet', 503);
    }

    $stmt = $pdo->prepare(
        'UPDATE company_flags
         SET is_active = ' . sql_bool(false) . ', cleared_at = ' . sql_now() . ', cleared_by_user_id = ?
         WHERE company_key = ? AND is_active = ' . sql_bool(true)
    );
    $stmt->execute([$userId, $companyKey]);
    if ($stmt->rowCount() < 1) {
        json_error('Active flag not found', 404);
    }
}

function company_flag_require_admin(): void {
    require_once __DIR__ . '/auth.php';
    if (!auth_is_admin()) {
        json_error('Admin access required', 403);
    }
}

/** Exact match first, then partial key/label matches (LIMIT 10). */
function company_flag_find(PDO $pdo, string $query): array {
    $key = company_flag_normalize_key($query);
    if ($key === '' || !company_flags_table_exists($pdo)) {
        return ['exact' => null, 'matches' => []];
    }

    $exact = company_flag_get_active($pdo, $key);
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $key) . '%';
    $stmt = $pdo->prepare(
        "SELECT cf.*, u.name AS flagged_by_name
         FROM company_flags cf
         LEFT JOIN users u ON u.id = cf.flagged_by_user_id
         WHERE cf.is_active = " . sql_bool(true) . "
           AND (cf.company_key LIKE ? OR UPPER(cf.company_label) LIKE ?)
         ORDER BY CASE WHEN cf.company_key = ? THEN 0 ELSE 1 END, cf.flagged_at DESC
         LIMIT 10"
    );
    $stmt->execute([$like, $like, $key]);
    $matches = $stmt->fetchAll() ?: [];

    return ['exact' => $exact, 'matches' => $matches];
}

/**
 * How many clients cashed checks tied to this company name.
 * Sources: caja_entries.company, barri paying_bank/description, transfers cheque types.
 */
function company_verification_history(PDO $pdo, string $label): array {
    $key = company_flag_normalize_key($label);
    if ($key === '') {
        return [
            'clients_count' => 0,
            'transactions_count' => 0,
            'total_amount' => 0.0,
            'caja_entry_count' => 0,
            'recent_clients' => [],
        ];
    }

    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $key) . '%';
    $clients = [];
    $txnCount = 0;
    $totalAmount = 0.0;

    // Barri / report cheque rows with client_id
    try {
        $stmt = $pdo->prepare(
            "SELECT bt.client_id, c.name AS client_name, COUNT(*) AS txn_count,
                    COALESCE(SUM(bt.total), 0) AS amount_sum,
                    MAX(bt.transaction_date) AS last_date
             FROM barri_transactions bt
             LEFT JOIN clients c ON c.id = bt.client_id
             WHERE (
                 UPPER(COALESCE(bt.paying_bank, '')) LIKE ?
                 OR UPPER(COALESCE(bt.description, '')) LIKE ?
                 OR UPPER(COALESCE(bt.beneficiary_name, '')) LIKE ?
             )
             AND (
                 LOWER(COALESCE(bt.transaction_type, '')) LIKE '%cheque%'
                 OR LOWER(COALESCE(bt.transaction_type, '')) LIKE '%cambio%'
                 OR LOWER(COALESCE(bt.transaction_type, '')) LIKE '%money order%'
                 OR bt.paying_bank IS NOT NULL
             )
             AND bt.client_id IS NOT NULL
             GROUP BY bt.client_id, c.name
             ORDER BY last_date DESC
             LIMIT 50"
        );
        $stmt->execute([$like, $like, $like]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $cid = (int)$row['client_id'];
            if ($cid < 1) continue;
            $clients[$cid] = [
                'client_id'   => $cid,
                'client_name' => (string)($row['client_name'] ?: ('#' . $cid)),
                'txn_count'   => (int)$row['txn_count'],
                'amount_sum'  => (float)$row['amount_sum'],
                'last_date'   => $row['last_date'],
            ];
            $txnCount += (int)$row['txn_count'];
            $totalAmount += (float)$row['amount_sum'];
        }
    } catch (Throwable $e) {
        // ponytail: table/column drift shouldn't break verify
        error_log('company_verification barri: ' . $e->getMessage());
    }

    // Transfers tagged as check cashing
    try {
        $stmt = $pdo->prepare(
            "SELECT t.client_id, c.name AS client_name, COUNT(*) AS txn_count,
                    COALESCE(SUM(t.amount_usd), 0) AS amount_sum,
                    MAX(t.date_sent) AS last_date
             FROM transfers t
             LEFT JOIN clients c ON c.id = t.client_id
             WHERE (
                 UPPER(COALESCE(t.company, '')) LIKE ?
                 OR UPPER(COALESCE(t.beneficiary, '')) LIKE ?
             )
             AND (
                 LOWER(COALESCE(t.transaction_type, '')) LIKE '%cheque%'
                 OR LOWER(COALESCE(t.transaction_type, '')) LIKE '%cambio%'
                 OR LOWER(COALESCE(t.company, '')) LIKE ?
             )
             GROUP BY t.client_id, c.name
             ORDER BY last_date DESC
             LIMIT 50"
        );
        $stmt->execute([$like, $like, $like]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $cid = (int)$row['client_id'];
            if ($cid < 1) continue;
            if (isset($clients[$cid])) {
                $clients[$cid]['txn_count'] += (int)$row['txn_count'];
                $clients[$cid]['amount_sum'] += (float)$row['amount_sum'];
                if (($row['last_date'] ?? '') > ($clients[$cid]['last_date'] ?? '')) {
                    $clients[$cid]['last_date'] = $row['last_date'];
                }
            } else {
                $clients[$cid] = [
                    'client_id'   => $cid,
                    'client_name' => (string)($row['client_name'] ?: ('#' . $cid)),
                    'txn_count'   => (int)$row['txn_count'],
                    'amount_sum'  => (float)$row['amount_sum'],
                    'last_date'   => $row['last_date'],
                ];
            }
            $txnCount += (int)$row['txn_count'];
            $totalAmount += (float)$row['amount_sum'];
        }
    } catch (Throwable $e) {
        error_log('company_verification transfers: ' . $e->getMessage());
    }

    $cajaCount = 0;
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM caja_entries WHERE UPPER(company) LIKE ?"
        );
        $stmt->execute([$like]);
        $cajaCount = (int)$stmt->fetchColumn();
        $txnCount += $cajaCount;
    } catch (Throwable $e) {
        error_log('company_verification caja: ' . $e->getMessage());
    }

    usort($clients, static fn($a, $b) => strcmp((string)($b['last_date'] ?? ''), (string)($a['last_date'] ?? '')));
    $recent = array_slice(array_values($clients), 0, 20);

    return [
        'clients_count'      => count($clients),
        'transactions_count' => $txnCount,
        'total_amount'       => round($totalAmount, 2),
        'caja_entry_count'   => $cajaCount,
        'recent_clients'     => $recent,
    ];
}

function company_verification_payload(PDO $pdo, string $label, ?array $parsed = null): array {
    $label = trim($label);
    $found = company_flag_find($pdo, $label);
    $history = company_verification_history($pdo, $label);
    $exact = $found['exact'];

    // ponytail: soft match = warn, exact = block; full fuzzy/OCR join later if needed
    $soft = ($exact === null && !empty($found['matches'])) ? $found['matches'][0] : null;
    $rec = 'unknown';
    if ($exact) {
        $rec = 'do_not_cash';
    } elseif ($soft) {
        $rec = 'possible_flag';
    } elseif (($history['clients_count'] ?? 0) >= 3) {
        $rec = 'known_ok';
    }

    return [
        'query'              => $label,
        'company_key'        => company_flag_normalize_key($label),
        'is_flagged'         => $exact !== null,
        'flag'               => $exact,
        'similar_flags'      => $found['matches'],
        'history'            => $history,
        'recommendation'     => $rec,
        'parsed'             => $parsed,
        'can_manage_flags'   => (function_exists('auth_is_admin') ? auth_is_admin() : false),
    ];
}
