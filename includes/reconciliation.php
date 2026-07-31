<?php

require_once __DIR__ . '/company-normalize.php';

/** Transaction types treated as cambio de cheques for cents loss. */
function recon_cambio_types(): array {
    return ['cambio_cheques', 'money_order', 'cheque_escaneado'];
}

function recon_is_cambio_type(string $type): bool {
    $t = strtolower(trim($type));
    if (in_array($t, recon_cambio_types(), true)) {
        return true;
    }
    return str_contains($t, 'cambio') && str_contains($t, 'cheque');
}

/** Fractional dollars lost when payout is truncated to whole dollars. */
function recon_cents_lost(float $payout): float {
    $abs = abs($payout);
    $whole = floor($abs);
    $cents = round($abs - $whole, 2);
    return $cents > 0 ? $cents : 0.0;
}

function recon_period_ref(int $storeId, string $company, string $periodMonth): string {
    return $storeId . '|' . company_normalize_key($company) . '|' . $periodMonth;
}

/**
 * Aggregate barri_transactions by store, company, and type for a date range.
 *
 * @return array<int, array<string, mixed>>
 */
function recon_transaction_summary(PDO $pdo, int $storeId, string $dateFrom, string $dateTo): array {
    $stmt = $pdo->prepare(
        'SELECT br.company AS report_company, bt.transaction_type,
                COUNT(*) AS txn_count,
                COALESCE(SUM(bt.principal), 0) AS sum_principal,
                COALESCE(SUM(bt.fee), 0) AS sum_fee,
                COALESCE(SUM(bt.total), 0) AS sum_total
         FROM barri_transactions bt
         INNER JOIN barri_reports br ON br.id = bt.report_id
         WHERE bt.store_id = ?
           AND bt.transaction_date >= ?
           AND bt.transaction_date <= ?
         GROUP BY br.company, bt.transaction_type
         ORDER BY br.company, bt.transaction_type'
    );
    $stmt->execute([$storeId, $dateFrom, $dateTo]);
    $rows = $stmt->fetchAll();

    $byCompany = [];
    foreach ($rows as $row) {
        $company = company_normalize($row['report_company'] ?? 'Barri');
        if (!isset($byCompany[$company])) {
            $byCompany[$company] = [
                'company'            => $company,
                'txn_count'          => 0,
                'cambio_count'       => 0,
                'giros_count'        => 0,
                'bill_payment_count' => 0,
                'other_count'        => 0,
                'sum_principal'      => 0.0,
                'sum_total'          => 0.0,
                'cents_lost'         => 0.0,
                'by_type'            => [],
            ];
        }

        $type = (string)($row['transaction_type'] ?? '');
        $count = (int)$row['txn_count'];
        $byCompany[$company]['txn_count'] += $count;
        $byCompany[$company]['sum_principal'] += (float)$row['sum_principal'];
        $byCompany[$company]['sum_total'] += (float)$row['sum_total'];

        if (recon_is_cambio_type($type)) {
            $byCompany[$company]['cambio_count'] += $count;
        } elseif ($type === 'giros') {
            $byCompany[$company]['giros_count'] += $count;
        } elseif ($type === 'bill_payment') {
            $byCompany[$company]['bill_payment_count'] += $count;
        } else {
            $byCompany[$company]['other_count'] += $count;
        }

        $byCompany[$company]['by_type'][$type] = [
            'count'         => $count,
            'sum_principal' => (float)$row['sum_principal'],
            'sum_total'     => (float)$row['sum_total'],
        ];
    }

    return array_values($byCompany);
}

/**
 * Compute cents lost per company from individual cambio transactions.
 *
 * @return array<string, float> company => cents
 */
function recon_cents_by_company(PDO $pdo, int $storeId, string $dateFrom, string $dateTo): array {
    $stmt = $pdo->prepare(
        'SELECT br.company AS report_company, bt.transaction_type, bt.total
         FROM barri_transactions bt
         INNER JOIN barri_reports br ON br.id = bt.report_id
         WHERE bt.store_id = ?
           AND bt.transaction_date >= ?
           AND bt.transaction_date <= ?'
    );
    $stmt->execute([$storeId, $dateFrom, $dateTo]);

    $cents = [];
    while ($row = $stmt->fetch()) {
        if (!recon_is_cambio_type((string)$row['transaction_type'])) {
            continue;
        }
        $company = company_normalize($row['report_company'] ?? 'Barri');
        $lost = recon_cents_lost((float)$row['total']);
        if ($lost <= 0) {
            continue;
        }
        $cents[$company] = ($cents[$company] ?? 0.0) + $lost;
    }

    foreach ($cents as $k => $v) {
        $cents[$k] = round($v, 2);
    }

    return $cents;
}

/** Merge cents into summary rows. */
function recon_summary_with_cents(PDO $pdo, int $storeId, string $dateFrom, string $dateTo): array {
    $summary = recon_transaction_summary($pdo, $storeId, $dateFrom, $dateTo);
    $centsMap = recon_cents_by_company($pdo, $storeId, $dateFrom, $dateTo);

    $periodMonth = substr($dateFrom, 0, 7);
    if (substr($dateTo, 0, 7) !== $periodMonth) {
        $periodMonth = substr($dateFrom, 0, 7);
    }

    $posted = recon_posted_cents_refs($pdo, $storeId, $periodMonth);

    foreach ($summary as &$row) {
        $company = $row['company'];
        $row['cents_lost'] = $centsMap[$company] ?? 0.0;
        $ref = recon_period_ref($storeId, $company, $periodMonth);
        $row['period_ref'] = $ref;
        $row['posted_to_ledger'] = isset($posted[$ref]);
        $row['posted_amount'] = $posted[$ref] ?? 0.0;
    }
    unset($row);

    foreach ($centsMap as $company => $amount) {
        $found = false;
        foreach ($summary as $row) {
            if ($row['company'] === $company) {
                $found = true;
                break;
            }
        }
        if (!$found && $amount > 0) {
            $ref = recon_period_ref($storeId, $company, $periodMonth);
            $summary[] = [
                'company'            => $company,
                'txn_count'          => 0,
                'cambio_count'       => 0,
                'giros_count'        => 0,
                'bill_payment_count' => 0,
                'other_count'        => 0,
                'sum_principal'      => 0.0,
                'sum_total'          => 0.0,
                'cents_lost'         => $amount,
                'by_type'            => [],
                'period_ref'         => $ref,
                'posted_to_ledger'   => isset($posted[$ref]),
                'posted_amount'      => $posted[$ref] ?? 0.0,
            ];
        }
    }

    usort($summary, fn($a, $b) => strcmp($a['company'], $b['company']));
    return $summary;
}

/**
 * Admin overview: all stores × company from parsed barri reports.
 *
 * @return array{summary: array<int, array<string, mixed>>, store_totals: array<int, array<string, mixed>>, reports: array<int, array<string, mixed>>}
 */
function recon_all_stores_overview(PDO $pdo, string $dateFrom, string $dateTo): array {
    $stmt = $pdo->prepare(
        'SELECT bt.store_id, s.name AS store_name, br.company AS report_company, bt.transaction_type,
                COUNT(*) AS txn_count,
                COALESCE(SUM(bt.principal), 0) AS sum_principal,
                COALESCE(SUM(bt.total), 0) AS sum_total
         FROM barri_transactions bt
         INNER JOIN barri_reports br ON br.id = bt.report_id
         INNER JOIN stores s ON s.id = bt.store_id
         WHERE bt.transaction_date >= ? AND bt.transaction_date <= ?
         GROUP BY bt.store_id, s.name, br.company, bt.transaction_type
         ORDER BY s.name, br.company, bt.transaction_type'
    );
    $stmt->execute([$dateFrom, $dateTo]);

    $rows = [];
    $keyIndex = [];
    while ($row = $stmt->fetch()) {
        $storeId = (int)$row['store_id'];
        $company = company_normalize($row['report_company'] ?? 'Barri');
        $key = $storeId . '|' . company_normalize_key($company);
        if (!isset($keyIndex[$key])) {
            $keyIndex[$key] = count($rows);
            $rows[] = [
                'store_id'           => $storeId,
                'store_name'         => (string)$row['store_name'],
                'company'            => $company,
                'txn_count'          => 0,
                'cambio_count'       => 0,
                'giros_count'        => 0,
                'bill_payment_count' => 0,
                'other_count'        => 0,
                'sum_principal'      => 0.0,
                'sum_total'          => 0.0,
                'cents_lost'         => 0.0,
                'report_count'       => 0,
                'report_files'       => [],
            ];
        }
        $i = $keyIndex[$key];
        $type = (string)$row['transaction_type'];
        $count = (int)$row['txn_count'];
        $rows[$i]['txn_count'] += $count;
        $rows[$i]['sum_principal'] += (float)$row['sum_principal'];
        $rows[$i]['sum_total'] += (float)$row['sum_total'];
        if (recon_is_cambio_type($type)) {
            $rows[$i]['cambio_count'] += $count;
        } elseif ($type === 'giros') {
            $rows[$i]['giros_count'] += $count;
        } elseif ($type === 'bill_payment') {
            $rows[$i]['bill_payment_count'] += $count;
        } else {
            $rows[$i]['other_count'] += $count;
        }
    }

    $centsStmt = $pdo->prepare(
        'SELECT bt.store_id, s.name AS store_name, br.company AS report_company, bt.transaction_type, bt.total
         FROM barri_transactions bt
         INNER JOIN barri_reports br ON br.id = bt.report_id
         INNER JOIN stores s ON s.id = bt.store_id
         WHERE bt.transaction_date >= ? AND bt.transaction_date <= ?'
    );
    $centsStmt->execute([$dateFrom, $dateTo]);
    while ($row = $centsStmt->fetch()) {
        if (!recon_is_cambio_type((string)$row['transaction_type'])) {
            continue;
        }
        $storeId = (int)$row['store_id'];
        $company = company_normalize($row['report_company'] ?? 'Barri');
        $key = $storeId . '|' . company_normalize_key($company);
        $lost = recon_cents_lost((float)$row['total']);
        if ($lost <= 0) {
            continue;
        }
        if (!isset($keyIndex[$key])) {
            $keyIndex[$key] = count($rows);
            $rows[] = [
                'store_id'           => $storeId,
                'store_name'         => (string)$row['store_name'],
                'company'            => $company,
                'txn_count'          => 0,
                'cambio_count'       => 0,
                'giros_count'        => 0,
                'bill_payment_count' => 0,
                'other_count'        => 0,
                'sum_principal'      => 0.0,
                'sum_total'          => 0.0,
                'cents_lost'         => 0.0,
                'report_count'       => 0,
                'report_files'       => [],
            ];
        }
        $rows[$keyIndex[$key]]['cents_lost'] += $lost;
    }

    $reports = recon_imported_reports($pdo, $dateFrom, $dateTo);
    foreach ($reports as $report) {
        $storeId = (int)$report['store_id'];
        $company = company_normalize($report['company'] ?? 'Barri');
        $key = $storeId . '|' . company_normalize_key($company);
        if (!isset($keyIndex[$key])) {
            continue;
        }
        $i = $keyIndex[$key];
        $rows[$i]['report_count']++;
        $label = $report['original_name'] ?: ('Report #' . $report['id']);
        if (!in_array($label, $rows[$i]['report_files'], true)) {
            $rows[$i]['report_files'][] = $label;
        }
    }

    $periodMonth = substr($dateFrom, 0, 7);
    foreach ($rows as &$row) {
        $row['cents_lost'] = round((float)$row['cents_lost'], 2);
        $ref = recon_period_ref((int)$row['store_id'], $row['company'], $periodMonth);
        $posted = recon_posted_cents_refs($pdo, (int)$row['store_id'], $periodMonth);
        $row['period_ref'] = $ref;
        $row['posted_to_ledger'] = isset($posted[$ref]);
        $row['posted_amount'] = $posted[$ref] ?? 0.0;
    }
    unset($row);

    usort($rows, function ($a, $b) {
        $cmp = strcmp($a['store_name'], $b['store_name']);
        return $cmp !== 0 ? $cmp : strcmp($a['company'], $b['company']);
    });

    $storeTotals = [];
    foreach ($rows as $row) {
        $sid = (int)$row['store_id'];
        if (!isset($storeTotals[$sid])) {
            $storeTotals[$sid] = [
                'store_id'     => $sid,
                'store_name'   => $row['store_name'],
                'cambio_count' => 0,
                'giros_count'  => 0,
                'txn_count'    => 0,
                'cents_lost'   => 0.0,
                'report_count' => 0,
            ];
        }
        $storeTotals[$sid]['cambio_count'] += (int)$row['cambio_count'];
        $storeTotals[$sid]['giros_count'] += (int)$row['giros_count'];
        $storeTotals[$sid]['txn_count'] += (int)$row['txn_count'];
        $storeTotals[$sid]['cents_lost'] += (float)$row['cents_lost'];
        $storeTotals[$sid]['report_count'] += (int)$row['report_count'];
    }
    foreach ($storeTotals as &$st) {
        $st['cents_lost'] = round($st['cents_lost'], 2);
    }
    unset($st);

    return [
        'summary'      => $rows,
        'store_totals' => array_values($storeTotals),
        'reports'      => $reports,
    ];
}

/** @return array<int, array<string, mixed>> */
function recon_imported_reports(PDO $pdo, string $dateFrom, string $dateTo): array {
    $stmt = $pdo->prepare(
        'SELECT br.id, br.store_id, s.name AS store_name, br.company, br.agency_number,
                br.report_date_from, br.report_date_to, br.total_transactions,
                br.original_name, br.status, br.created_at
         FROM barri_reports br
         INNER JOIN stores s ON s.id = br.store_id
         WHERE br.report_date_to >= ? AND br.report_date_from <= ?
         ORDER BY s.name, br.report_date_from DESC, br.id DESC'
    );
    $stmt->execute([$dateFrom, $dateTo]);
    return $stmt->fetchAll();
}

/** @return array<string, float> source_ref => owed_to_store amount */
function recon_posted_cents_refs(PDO $pdo, int $storeId, string $periodMonth): array {
    $like = $storeId . '|%|' . $periodMonth;
    $stmt = $pdo->prepare(
        "SELECT source_ref, owed_to_store FROM internal_ledger
         WHERE store_id = ? AND entry_source = 'cents_auto' AND source_ref LIKE ?"
    );
    $stmt->execute([$storeId, $like]);
    $out = [];
    while ($row = $stmt->fetch()) {
        if (!empty($row['source_ref'])) {
            $out[(string)$row['source_ref']] = (float)$row['owed_to_store'];
        }
    }
    return $out;
}

/**
 * Detail rows for cambio transactions with cents loss.
 *
 * @return array<int, array<string, mixed>>
 */
function recon_cambio_detail(PDO $pdo, int $storeId, string $dateFrom, string $dateTo, ?string $companyFilter = null): array {
    $stmt = $pdo->prepare(
        'SELECT bt.id, bt.transaction_date, bt.transaction_time, bt.transaction_type,
                bt.reference_number, bt.customer_name, bt.total, bt.principal, bt.fee,
                br.company AS report_company, br.id AS report_id
         FROM barri_transactions bt
         INNER JOIN barri_reports br ON br.id = bt.report_id
         WHERE bt.store_id = ?
           AND bt.transaction_date >= ?
           AND bt.transaction_date <= ?
         ORDER BY bt.transaction_date DESC, bt.transaction_time DESC, bt.id DESC
         LIMIT 500'
    );
    $stmt->execute([$storeId, $dateFrom, $dateTo]);

    $rows = [];
    while ($row = $stmt->fetch()) {
        if (!recon_is_cambio_type((string)$row['transaction_type'])) {
            continue;
        }
        $company = company_normalize($row['report_company'] ?? 'Barri');
        if ($companyFilter !== null && company_normalize($companyFilter) !== $company) {
            continue;
        }
        $lost = recon_cents_lost((float)$row['total']);
        if ($lost <= 0) {
            continue;
        }
        $row['company'] = $company;
        $row['cents_lost'] = $lost;
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Compare caja (Excel) totals vs barri report totals for a single day; upsert variances.
 *
 * @return array<int, array<string, mixed>>
 */
function recon_compare_caja_to_reports(PDO $pdo, int $storeId, string $sessionDate, ?int $importId = null): array {
    $cajaStmt = $pdo->prepare(
        'SELECT ce.company,
                COALESCE(SUM(ce.cash_in), 0) AS cash_in,
                COALESCE(SUM(ce.checks_debits), 0) AS checks_debits,
                COALESCE(SUM(ce.total), 0) AS excel_total
         FROM caja_entries ce
         INNER JOIN caja_sessions cs ON cs.id = ce.session_id
         WHERE cs.store_id = ? AND cs.session_date = ?
         GROUP BY ce.company'
    );
    $cajaStmt->execute([$storeId, $sessionDate]);
    $cajaRows = $cajaStmt->fetchAll();

    $reportStmt = $pdo->prepare(
        'SELECT br.company,
                COALESCE(SUM(bt.principal), 0) AS report_total,
                COUNT(*) AS txn_count
         FROM barri_transactions bt
         INNER JOIN barri_reports br ON br.id = bt.report_id
         WHERE bt.store_id = ? AND bt.transaction_date = ?
         GROUP BY br.company'
    );
    $reportStmt->execute([$storeId, $sessionDate]);
    $reportByCompany = [];
    foreach ($reportStmt->fetchAll() as $r) {
        $reportByCompany[company_normalize($r['company'])] = $r;
    }

    $variances = [];
    $seen = [];

    foreach ($cajaRows as $caja) {
        $company = company_normalize($caja['company']);
        $seen[$company] = true;
        $excelTotal = (float)$caja['excel_total'];
        $reportTotal = (float)($reportByCompany[$company]['report_total'] ?? 0);
        $diff = round($excelTotal - $reportTotal, 2);

        if (abs($diff) < 0.01 && $reportTotal > 0) {
            continue;
        }
        if ($excelTotal <= 0 && $reportTotal <= 0) {
            continue;
        }

        $variance = recon_upsert_variance($pdo, [
            'store_id'        => $storeId,
            'company'         => $company,
            'variance_date'   => $sessionDate,
            'metric'          => 'daily_total',
            'excel_amount'    => $excelTotal,
            'report_amount'   => $reportTotal,
            'diff_amount'     => $diff,
            'excel_import_id' => $importId,
        ]);
        if ($variance) {
            $variances[] = $variance;
        }
    }

    foreach ($reportByCompany as $company => $report) {
        if (isset($seen[$company])) {
            continue;
        }
        $reportTotal = (float)$report['report_total'];
        if ($reportTotal <= 0) {
            continue;
        }
        $variance = recon_upsert_variance($pdo, [
            'store_id'        => $storeId,
            'company'         => $company,
            'variance_date'   => $sessionDate,
            'metric'          => 'daily_total',
            'excel_amount'    => 0,
            'report_amount'   => $reportTotal,
            'diff_amount'     => round(0 - $reportTotal, 2),
            'excel_import_id' => $importId,
        ]);
        if ($variance) {
            $variances[] = $variance;
        }
    }

    return $variances;
}

/** @param array<string, mixed> $data */
function recon_upsert_variance(PDO $pdo, array $data): ?array {
    if (abs((float)$data['diff_amount']) < 0.01) {
        return null;
    }

    $existing = $pdo->prepare(
        'SELECT id FROM reconciliation_variances
         WHERE store_id = ? AND company = ? AND variance_date = ? AND metric = ?'
    );
    $existing->execute([
        $data['store_id'],
        $data['company'],
        $data['variance_date'],
        $data['metric'],
    ]);
    $row = $existing->fetch();

    if ($row) {
        $pdo->prepare(
            'UPDATE reconciliation_variances
             SET excel_amount = ?, report_amount = ?, diff_amount = ?,
                 excel_import_id = COALESCE(?, excel_import_id), status = ?
             WHERE id = ?'
        )->execute([
            $data['excel_amount'],
            $data['report_amount'],
            $data['diff_amount'],
            $data['excel_import_id'] ?? null,
            'open',
            (int)$row['id'],
        ]);
        $id = (int)$row['id'];
    } else {
        $pdo->prepare(
            'INSERT INTO reconciliation_variances
             (store_id, company, variance_date, metric, excel_amount, report_amount, diff_amount, excel_import_id, status)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $data['store_id'],
            $data['company'],
            $data['variance_date'],
            $data['metric'],
            $data['excel_amount'],
            $data['report_amount'],
            $data['diff_amount'],
            $data['excel_import_id'] ?? null,
            'open',
        ]);
        $id = (int)sql_last_insert_id($pdo, 'reconciliation_variances');
    }

    $stmt = $pdo->prepare('SELECT * FROM reconciliation_variances WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** @return array<int, array<string, mixed>> */
function recon_list_variances(PDO $pdo, ?int $storeId, string $status = 'open', int $limit = 100, ?string $dateFrom = null, ?string $dateTo = null): array {
    $sql = 'SELECT rv.*, s.name AS store_name
            FROM reconciliation_variances rv
            LEFT JOIN stores s ON s.id = rv.store_id
            WHERE 1=1';
    $params = [];
    if ($storeId !== null) {
        $sql .= ' AND rv.store_id = ?';
        $params[] = $storeId;
    }
    if ($dateFrom !== null) {
        $sql .= ' AND rv.variance_date >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== null) {
        $sql .= ' AND rv.variance_date <= ?';
        $params[] = $dateTo;
    }
    if ($status !== 'all') {
        $sql .= ' AND rv.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY rv.variance_date DESC, rv.id DESC LIMIT ' . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Post aggregated cents owed to internal_ledger (admin action).
 *
 * @return array<string, mixed>
 */
function recon_post_cents_to_ledger(PDO $pdo, int $storeId, string $company, string $periodMonth, string $dateFrom, string $dateTo): array {
    $centsMap = recon_cents_by_company($pdo, $storeId, $dateFrom, $dateTo);
    $companyNorm = company_normalize($company);
    $amount = round($centsMap[$companyNorm] ?? 0.0, 2);

    if ($amount <= 0) {
        return ['success' => false, 'error' => 'No cents loss found for this company and period'];
    }

    $ref = recon_period_ref($storeId, $companyNorm, $periodMonth);
    $dup = $pdo->prepare(
        "SELECT id FROM internal_ledger WHERE store_id = ? AND entry_source = 'cents_auto' AND source_ref = ? LIMIT 1"
    );
    $dup->execute([$storeId, $ref]);
    if ($dup->fetch()) {
        return ['success' => false, 'error' => 'Cents for this period were already posted to the ledger'];
    }

    $desc = sprintf(
        'Centavos no reportados — %s (%s)',
        $companyNorm,
        $periodMonth
    );
    $notes = sprintf(
        'Auto: %d cambio cheques truncados, %s–%s',
        recon_cambio_count_for_company($pdo, $storeId, $companyNorm, $dateFrom, $dateTo),
        $dateFrom,
        $dateTo
    );

    $pdo->prepare(
        'INSERT INTO internal_ledger
         (store_id, employee_name, description, owed_to_store, store_owes, entry_date, notes, company, entry_source, source_ref)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $storeId,
        '',
        $desc,
        $amount,
        0,
        $dateTo,
        $notes,
        $companyNorm,
        'cents_auto',
        $ref,
    ]);

    return [
        'success' => true,
        'id'      => (int)sql_last_insert_id($pdo, 'internal_ledger'),
        'amount'  => $amount,
        'ref'     => $ref,
    ];
}

function recon_cambio_count_for_company(PDO $pdo, int $storeId, string $company, string $dateFrom, string $dateTo): int {
    $stmt = $pdo->prepare(
        'SELECT bt.transaction_type, br.company
         FROM barri_transactions bt
         INNER JOIN barri_reports br ON br.id = bt.report_id
         WHERE bt.store_id = ? AND bt.transaction_date >= ? AND bt.transaction_date <= ?'
    );
    $stmt->execute([$storeId, $dateFrom, $dateTo]);
    $count = 0;
    while ($row = $stmt->fetch()) {
        if (company_normalize($row['company']) !== $company) {
            continue;
        }
        if (recon_is_cambio_type((string)$row['transaction_type'])) {
            $count++;
        }
    }
    return $count;
}

function recon_dismiss_variance(PDO $pdo, int $varianceId, int $storeId, int $userId): bool {
    $stmt = $pdo->prepare(
        'UPDATE reconciliation_variances
         SET status = ?, reviewed_at = ' . sql_now() . ', reviewed_by_user_id = ?
         WHERE id = ? AND store_id = ? AND status = ?'
    );
    $stmt->execute(['reviewed', $userId, $varianceId, $storeId, 'open']);
    return $stmt->rowCount() > 0;
}

/** Run caja comparison after Excel caja import. */
function recon_after_caja_import(PDO $pdo, int $storeId, ?int $importId = null): array {
    $today = date('Y-m-d');
    return recon_compare_caja_to_reports($pdo, $storeId, $today, $importId);
}

/** Refresh variances for each day in a report period that has caja data. */
function recon_refresh_report_period(PDO $pdo, int $storeId, string $dateFrom, string $dateTo): array {
    $variances = [];
    try {
        $start = new DateTime($dateFrom);
        $end = new DateTime($dateTo);
    } catch (Exception $e) {
        return [];
    }

    $check = $pdo->prepare('SELECT id FROM caja_sessions WHERE store_id = ? AND session_date = ? LIMIT 1');
    while ($start <= $end) {
        $day = $start->format('Y-m-d');
        $check->execute([$storeId, $day]);
        if ($check->fetch()) {
            $variances = array_merge(
                $variances,
                recon_compare_caja_to_reports($pdo, $storeId, $day)
            );
        }
        $start->modify('+1 day');
    }

    return $variances;
}
