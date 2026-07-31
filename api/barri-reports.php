<?php
require_once __DIR__ . '/../includes/report-metadata.php';

$user = auth_require();
$method = get_method();
$pdo = db();

function barri_normalize_report_data(array $data): array {
    $data['report_date_from'] = $data['report_date_from'] ?? $data['date_from'] ?? null;
    $data['report_date_to'] = $data['report_date_to'] ?? $data['date_to'] ?? null;
    $totals = $data['totals'] ?? [];
    $data['total_transactions'] = $data['total_transactions'] ?? ($totals['qty'] ?? 0);
    $data['total_principal'] = $data['total_principal'] ?? ($totals['principal'] ?? 0);
    $data['total_fees'] = $data['total_fees'] ?? ($totals['fee'] ?? 0);
    $data['total_tax'] = $data['total_tax'] ?? ($totals['tax'] ?? 0);
    $data['total_amount'] = $data['total_amount'] ?? ($totals['total'] ?? 0);
    $data['total_agcomm'] = $data['total_agcomm'] ?? ($totals['agcomm'] ?? 0);
    if (empty($data['agency_name'])) $data['agency_name'] = 'Unknown Agency';
    if (empty($data['report_date_from'])) $data['report_date_from'] = date('Y-m-d');
    if (empty($data['report_date_to'])) $data['report_date_to'] = $data['report_date_from'];
    return $data;
}

function barri_report_label(array $data): string {
    $agency = trim($data['agency_number'] ?? '') ?: trim($data['agency_name'] ?? 'Report');
    return $agency . ' (' . ($data['report_date_from'] ?? '?') . ' — ' . ($data['report_date_to'] ?? '?') . ')';
}

/** True when a txn is a nameless Money Order / other service (no FinCEN client). */
function barri_is_nameless_money_order(array $txn): bool {
    $rawType = strtolower(trim((string)($txn['transaction_type'] ?? $txn['type'] ?? '')));
    $type = str_replace(' ', '_', $rawType);
    if ($type !== 'money_order') {
        return false;
    }
    $name = trim((string)($txn['customer_name'] ?? ''));
    if ($name === '') {
        return true;
    }
    $placeholder = ['money order', 'giro postal', 'money_order', 'otros servicios', 'other services'];
    return in_array(strtolower($name), $placeholder, true);
}

/** Post Money Order / Otros servicios totals for mixed remittance reports. */
function barri_create_money_order_side_accounting(PDO $pdo, int $storeId, int $reportId, array $data, array $moTxns): void {
    if ($moTxns === []) {
        return;
    }
    $dup = $pdo->prepare("SELECT id FROM accounting_entries WHERE source_report_id = ? AND category = 'Money Orders' LIMIT 1");
    $dup->execute([$reportId]);
    if ($dup->fetch()) {
        return;
    }

    $principal = 0.0;
    $fees = 0.0;
    foreach ($moTxns as $txn) {
        $principal += (float)($txn['principal'] ?? 0);
        $fees += (float)($txn['fee'] ?? 0);
    }
    $count = count($moTxns);
    $company = sanitize($data['company'] ?? 'Viamericas');
    $agency = trim($data['agency_number'] ?? '') ?: trim($data['agency_name'] ?? 'Agency');
    $from = $data['report_date_from'] ?? $data['date_from'] ?? date('Y-m-d');
    $to = $data['report_date_to'] ?? $data['date_to'] ?? $from;
    $notes = "Otros servicios — {$count} money orders without client names. Principal \${$principal}. Fees \${$fees}. Source report #{$reportId}.";

    $ins = $pdo->prepare('INSERT INTO accounting_entries (store_id, category, description, amount, entry_type, entry_date, notes, finance_class, data_completeness, source_report_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $ins->execute([
        $storeId,
        'Money Orders',
        "{$company} Money Orders {$agency} ({$from} — {$to})",
        $principal + $fees,
        'receivable',
        $to,
        $notes,
        'side_finances',
        'incomplete_vital',
        $reportId,
    ]);
}

/** Post side-finance summary lines to accounting (incomplete but vital). */
function barri_create_side_finance_accounting(PDO $pdo, int $storeId, int $reportId, array $data): void {
    $dup = $pdo->prepare('SELECT id FROM accounting_entries WHERE source_report_id = ? LIMIT 1');
    $dup->execute([$reportId]);
    if ($dup->fetch()) return;

    $agency = trim($data['agency_number'] ?? '') ?: 'Intermex';
    $from = $data['report_date_from'] ?? $data['date_from'] ?? date('Y-m-d');
    $to = $data['report_date_to'] ?? $data['date_to'] ?? $from;
    $txnCount = (int)($data['total_transactions'] ?? 0);
    $totalPrincipal = (float)($data['total_principal'] ?? 0);
    $totalAgcomm = (float)($data['total_agcomm'] ?? 0);
    $totalTax = (float)($data['total_tax'] ?? 0);
    $company = sanitize($data['company'] ?? 'Intermex');

    $notes = "Incomplete but vital — {$txnCount} transfers without client names. Principal volume \${$totalPrincipal}. Tax \${$totalTax}. Source report #{$reportId}.";

    $ins = $pdo->prepare('INSERT INTO accounting_entries (store_id, category, description, amount, entry_type, entry_date, notes, finance_class, data_completeness, source_report_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $ins->execute([
        $storeId,
        'Side Finances',
        "{$company} Giros Enviados {$agency} ({$from} — {$to})",
        $totalAgcomm > 0 ? $totalAgcomm : $totalPrincipal,
        'receivable',
        $to,
        $notes,
        'side_finances',
        'incomplete_vital',
        $reportId,
    ]);
}

/** @return array<int, string> reference numbers already stored for this store */
function barri_find_existing_transaction_refs(PDO $pdo, int $storeId, array $refs): array {
    $refs = array_values(array_unique(array_filter(array_map(static function ($r) {
        return trim((string)$r);
    }, $refs))));
    if ($refs === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($refs), '?'));
    $stmt = $pdo->prepare(
        "SELECT DISTINCT reference_number FROM barri_transactions
         WHERE store_id = ? AND reference_number IN ($placeholders)"
    );
    $stmt->execute(array_merge([$storeId], $refs));
    return array_column($stmt->fetchAll(), 'reference_number');
}

/** @return list<array<string, mixed>> */
function barri_find_duplicate_transactions_detail(PDO $pdo, int $storeId, array $refs): array {
    $refs = array_values(array_unique(array_filter(array_map(static function ($r) {
        return trim((string)$r);
    }, $refs))));
    if ($refs === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($refs), '?'));
    $stmt = $pdo->prepare(
        "SELECT bt.reference_number AS reference, bt.report_id, bt.transaction_date, bt.customer_name,
                br.report_date_from, br.report_date_to, br.agency_number, br.agency_name
         FROM barri_transactions bt
         INNER JOIN barri_reports br ON br.id = bt.report_id
         WHERE bt.store_id = ? AND bt.reference_number IN ($placeholders)
         ORDER BY bt.reference_number"
    );
    $stmt->execute(array_merge([$storeId], $refs));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function barri_transaction_ref_exists(PDO $pdo, int $storeId, string $ref): bool {
    $ref = trim($ref);
    if ($ref === '') {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT 1 FROM barri_transactions WHERE store_id = ? AND reference_number = ? LIMIT 1'
    );
    $stmt->execute([$storeId, $ref]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Remove transactions already in DB (by reference) and duplicate refs within the same upload.
 *
 * @return array{transactions: array<int, array>, skipped_duplicates: int, skipped_in_file_duplicates: int}
 */
function barri_filter_import_transactions(PDO $pdo, int $storeId, array $transactions, bool $skipTxnDuplicates): array {
    if (!$skipTxnDuplicates || $transactions === []) {
        return [
            'transactions'              => $transactions,
            'skipped_duplicates'        => 0,
            'skipped_in_file_duplicates'=> 0,
        ];
    }

    $refs = [];
    foreach ($transactions as $txn) {
        $r = trim((string)($txn['reference_number'] ?? $txn['reference'] ?? ''));
        if ($r !== '') {
            $refs[] = $r;
        }
    }

    $existingSet = [];
    if ($refs !== []) {
        $existingSet = array_flip(barri_find_existing_transaction_refs($pdo, $storeId, $refs));
    }

    $seenInFile = [];
    $filtered = [];
    $skipped = 0;
    $skippedInFile = 0;

    foreach ($transactions as $txn) {
        $r = trim((string)($txn['reference_number'] ?? $txn['reference'] ?? ''));
        if ($r !== '') {
            if (isset($existingSet[$r])) {
                $skipped++;
                continue;
            }
            if (isset($seenInFile[$r])) {
                $skipped++;
                $skippedInFile++;
                continue;
            }
            $seenInFile[$r] = true;
        }
        $filtered[] = $txn;
    }

    return [
        'transactions'               => $filtered,
        'skipped_duplicates'         => $skipped,
        'skipped_in_file_duplicates' => $skippedInFile,
    ];
}

function barri_resolve_import_store_id(PDO $pdo, array $user, array $data, ?int $explicitStore): array {
    $autoStoreId = barri_auto_match_store($pdo, $data);
    $unassigned = $autoStoreId === null;

    if ($autoStoreId) {
        $storeId = $autoStoreId;
        auth_require_store_access($storeId);
    } elseif ($explicitStore) {
        $storeId = $explicitStore;
        auth_require_store_access($storeId);
    } else {
        $storeId = resolve_store_id();
    }

    return ['store_id' => $storeId, 'unassigned' => $unassigned, 'auto_matched' => !$unassigned];
}

function barri_recompute_payload_totals(array $data): array {
    $format = $data['report_format'] ?? $data['report_type'] ?? '';
    if ($format === 'agency_activity') {
        return barri_finalize_agency_activity_totals($data);
    }
    $txns = $data['transactions'] ?? [];
    $totals = [
        'qty' => count($txns),
        'principal' => 0.0,
        'fee' => 0.0,
        'tax' => 0.0,
        'total' => 0.0,
        'agcomm' => 0.0,
    ];
    foreach ($txns as $txn) {
        $totals['principal'] += (float)($txn['principal'] ?? 0);
        $totals['fee'] += (float)($txn['fee'] ?? 0);
        $totals['tax'] += (float)($txn['tax'] ?? 0);
        $totals['total'] += (float)($txn['total'] ?? 0);
        $totals['agcomm'] += (float)($txn['agcomm'] ?? $txn['ag_commission'] ?? 0);
    }
    foreach (['principal', 'fee', 'tax', 'total', 'agcomm'] as $k) {
        $totals[$k] = round($totals[$k], 2);
    }
    $data['transactions'] = $txns;
    $data['totals'] = $totals;
    $data['total_transactions'] = $totals['qty'];
    $data['total_principal'] = $totals['principal'];
    $data['total_fees'] = $totals['fee'];
    $data['total_tax'] = $totals['tax'];
    $data['total_amount'] = $totals['total'];
    $data['total_agcomm'] = $totals['agcomm'];
    return $data;
}

function barri_finalize_agency_activity_totals(array $data): array {
    $txns = $data['transactions'] ?? [];
    $agcomm = 0.0;
    foreach ($txns as $txn) {
        $agcomm += (float)($txn['agcomm'] ?? $txn['ag_commission'] ?? 0);
    }
    $agcomm = round($agcomm, 2);
    $begin = (float)($data['beginning_balance'] ?? 0);
    $end = (float)($data['ending_balance'] ?? 0);
    $balanceChange = round($end - $begin, 2);

    $data['report_format'] = 'agency_activity';
    $data['report_type'] = 'agency_activity';
    $data['balance_change'] = $balanceChange;
    $data['totals'] = [
        'qty' => count($txns),
        'principal' => $balanceChange,
        'fee' => 0.0,
        'tax' => 0.0,
        'total' => $agcomm,
        'agcomm' => $agcomm,
    ];
    $data['total_transactions'] = count($txns);
    $data['total_principal'] = $balanceChange;
    $data['total_fees'] = 0.0;
    $data['total_tax'] = 0.0;
    $data['total_amount'] = $agcomm;
    $data['total_agcomm'] = $agcomm;
    return $data;
}

function barri_refresh_report_totals(PDO $pdo, int $reportId): void {
    $metaStmt = $pdo->prepare('SELECT beginning_balance, ending_balance, report_type FROM barri_reports WHERE id = ?');
    $metaStmt->execute([$reportId]);
    $meta = $metaStmt->fetch(PDO::FETCH_ASSOC);
    if (!$meta) {
        return;
    }

    if (($meta['report_type'] ?? '') === 'agency_activity') {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS cnt, COALESCE(SUM(ag_commission), 0) AS agcomm
             FROM barri_transactions WHERE report_id = ?'
        );
        $stmt->execute([$reportId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $balanceChange = round((float)$meta['ending_balance'] - (float)$meta['beginning_balance'], 2);
        $agcomm = round((float)($row['agcomm'] ?? 0), 2);
        $pdo->prepare(
            'UPDATE barri_reports SET total_transactions = ?, total_principal = ?, total_fees = 0, total_tax = 0, total_amount = ?, total_agcomm = ? WHERE id = ?'
        )->execute([
            (int)$row['cnt'],
            $balanceChange,
            $agcomm,
            $agcomm,
            $reportId,
        ]);
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS cnt, COALESCE(SUM(principal), 0) AS principal, COALESCE(SUM(fee), 0) AS fee,
                COALESCE(SUM(tax), 0) AS tax, COALESCE(SUM(total), 0) AS total,
                COALESCE(SUM(ag_commission), 0) AS agcomm
         FROM barri_transactions WHERE report_id = ?'
    );
    $stmt->execute([$reportId]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }
    $pdo->prepare(
        'UPDATE barri_reports SET total_transactions = ?, total_principal = ?, total_fees = ?, total_tax = ?, total_amount = ?, total_agcomm = ? WHERE id = ?'
    )->execute([
        (int)$row['cnt'],
        round((float)$row['principal'], 2),
        round((float)$row['fee'], 2),
        round((float)$row['tax'], 2),
        round((float)$row['total'], 2),
        round((float)$row['agcomm'], 2),
        $reportId,
    ]);
}

function barri_truncate(string $value, int $maxLen): string {
    if ($maxLen <= 0) return '';
    if (function_exists('mb_substr')) {
        return mb_strlen($value) > $maxLen ? mb_substr($value, 0, $maxLen) : $value;
    }
    return strlen($value) > $maxLen ? substr($value, 0, $maxLen) : $value;
}

function barri_import_report(PDO $pdo, array $user, array $data, array $options = []): array {
    $explicitStore = $options['explicit_store'] ?? null;
    $pdfFile = $options['pdf_file'] ?? null;
    $skipDuplicates = $options['skip_duplicates'] ?? true;
    $skipTxnDuplicates = $options['skip_duplicate_transactions'] ?? true;
    $sourceLabel = $options['source_label'] ?? barri_report_label($data);

    try {
        // Large Viamericas/Estado reports can be 40+ pages / 900+ txns.
        set_time_limit(300);
        $data = barri_normalize_report_data($data);
        validate_required($data, ['agency_name', 'report_date_from', 'report_date_to', 'transactions']);

        $storeCtx = barri_resolve_import_store_id($pdo, $user, $data, $explicitStore ? (int)$explicitStore : null);
        $storeId = $storeCtx['store_id'];
        $unassigned = $storeCtx['unassigned'];

        $pdfPath = '';
        $originalName = '';
        if (!empty($pdfFile) && ($pdfFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $pdfPath = upload_file($pdfFile, 'barri-reports') ?: '';
            $originalName = $pdfFile['name'] ?? '';
        }

        $dateFrom = $data['report_date_from'];
        $dateTo = $data['report_date_to'];
        $agencyNum = sanitize($data['agency_number'] ?? '');

        $existingReportId = null;
        if ($skipDuplicates) {
            $dup = $pdo->prepare('SELECT id FROM barri_reports WHERE store_id = ? AND agency_number = ? AND report_date_from = ? AND report_date_to = ?');
            $dup->execute([$storeId, $agencyNum, $dateFrom, $dateTo]);
            $existing = $dup->fetch();
            if ($existing) {
                $existingReportId = (int)$existing['id'];
            }
        }

        $skippedTxnDuplicates = 0;
        $filterResult = barri_filter_import_transactions(
            $pdo,
            $storeId,
            $data['transactions'] ?? [],
            $skipTxnDuplicates
        );
        $data['transactions'] = $filterResult['transactions'];
        $skippedTxnDuplicates = $filterResult['skipped_duplicates'];

        if (empty($data['transactions'])) {
            return [
                'status' => 'duplicate',
                'label' => $sourceLabel,
                'report_id' => $existingReportId,
                'store_id' => $storeId,
                'unassigned' => $unassigned,
                'auto_matched' => !$unassigned,
                'imported_transaction_count' => 0,
                'skipped_transaction_duplicates' => $skippedTxnDuplicates,
                'skipped_in_file_duplicates' => $filterResult['skipped_in_file_duplicates'],
                'error' => $skippedTxnDuplicates > 0 || $existingReportId
                    ? 'All transactions in this file are already imported'
                    : 'No transactions to import',
            ];
        }

        $data = barri_recompute_payload_totals($data);
        $appendToExisting = $existingReportId !== null;

        $company = sanitize($data['company'] ?? 'Barri');
        $storeName = sanitize($data['store_name'] ?? '');
        $arExec = sanitize($data['ar_executive'] ?? '');
        $reportPhone = sanitize($data['phone'] ?? '');

        $reportType = 'barri';
        $companyLower = strtolower($company);
        $financeClass = sanitize($data['finance_class'] ?? 'standard');
        $dataCompleteness = sanitize($data['data_completeness'] ?? 'complete');
        $isSideFinance = $financeClass === 'side_finances'
            || ($data['report_format'] ?? '') === 'intermex_giros_enviados';
        if ($isSideFinance) {
            $financeClass = 'side_finances';
            $dataCompleteness = 'incomplete_vital';
        }
        if (($data['report_format'] ?? '') === 'agency_activity' || ($data['report_type'] ?? '') === 'agency_activity') {
            $reportType = 'agency_activity';
        } elseif ($isSideFinance) {
            $reportType = 'intermex_side';
        } elseif (str_contains($companyLower, 'viamerica')) $reportType = 'viamericas';
        elseif (str_contains($companyLower, 'intermex')) $reportType = 'intermex';
        elseif (str_contains($companyLower, 'intercambio')) $reportType = 'intercambio';
        elseif (str_contains($companyLower, 'ria')) $reportType = 'ria';

        $pdo->beginTransaction();

        if ($appendToExisting) {
            $reportId = $existingReportId;
        } else {
            $ins = $pdo->prepare('INSERT INTO barri_reports (store_id, user_id, agency_number, agency_name, agency_address, company, store_name, ar_executive, phone, report_date_from, report_date_to, beginning_balance, ending_balance, total_transactions, total_principal, total_fees, total_tax, total_amount, total_agcomm, filename, original_name, status, report_type, finance_class, data_completeness) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $ins->execute([
                $storeId, $user['id'], $agencyNum, sanitize($data['agency_name']),
                sanitize($data['agency_address'] ?? ''), $company, $storeName, $arExec, $reportPhone,
                $dateFrom, $dateTo,
                (float)($data['beginning_balance'] ?? 0), (float)($data['ending_balance'] ?? 0),
                (int)($data['total_transactions'] ?? 0), (float)($data['total_principal'] ?? 0),
                (float)($data['total_fees'] ?? 0), (float)($data['total_tax'] ?? 0),
                (float)($data['total_amount'] ?? 0), (float)($data['total_agcomm'] ?? 0),
                $pdfPath, sanitize($originalName),
                'pending', $reportType, $financeClass, $dataCompleteness
            ]);
            $reportId = sql_last_insert_id($pdo, 'barri_reports');
        }

        $txnIns = $pdo->prepare('INSERT INTO barri_transactions (report_id, store_id, client_id, transaction_time, transaction_date, transaction_type, reference_number, customer_name, beneficiary_name, description, operator, quantity, principal, fee, tax, total, running_balance, ag_commission, variable_fee, variable_fx, matched, amount_received, received_currency, paying_bank, destination_country, destination_state, destination_city, payment_date, transaction_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $transferIns = $pdo->prepare('INSERT INTO transfers (client_id, store_id, transaction_code, beneficiary, date_sent, amount_usd, fee, tax, company, transaction_type, source) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $markPushed = $pdo->prepare('UPDATE barri_transactions SET pushed_to_transfers = ' . sql_bool(true) . ', transfer_id = ? WHERE id = ?');
        $clientLookup = $pdo->prepare('SELECT id, name FROM clients WHERE LOWER(name) = LOWER(?) LIMIT 2');
        $clientInsert = $pdo->prepare('INSERT INTO clients (name, phone, monthly_limit, notes) VALUES (?,?,3000,?)');

        // Cache lookups — large Estado reports repeat many sender names.
        $clientCache = [];

        $matchedCount = 0;
        $unmatchedCount = 0;
        $createdCount = 0;
        $pushedCount = 0;
        $sideFinanceCount = 0;
        $skippedMissingCustomer = 0;
        $namelessMoneyOrders = [];
        $importedRefsThisBatch = [];
        foreach ($data['transactions'] as $txnIndex => $txn) {
            $custName = trim($txn['customer_name'] ?? '');
            $isNamelessMo = barri_is_nameless_money_order($txn);
            $isSideTxn = $isSideFinance
                || (($txn['finance_class'] ?? '') === 'side_finances')
                || $isNamelessMo;
            if (!$isSideTxn && !$custName) {
                $skippedMissingCustomer++;
                continue;
            }

            $txnDate = !empty($txn['transaction_date']) ? sanitize($txn['transaction_date']) : $dateFrom;

            $clientId = null;
            $isMatched = false;
            if ($isSideTxn) {
                $refLabel = trim($txn['reference'] ?? $txn['reference_number'] ?? '');
                $custName = trim($txn['side_label'] ?? '')
                    ?: ($isNamelessMo && $refLabel !== '' ? ('MO ' . $refLabel) : '')
                    ?: ($refLabel !== '' ? $refLabel : 'Side Finances');
                $sideFinanceCount++;
                if ($isNamelessMo) {
                    $namelessMoneyOrders[] = $txn;
                }
            } else {
                $cacheKey = mb_strtolower($custName, 'UTF-8');
                if (array_key_exists($cacheKey, $clientCache)) {
                    $cached = $clientCache[$cacheKey];
                    if ($cached === false) {
                        $unmatchedCount++;
                    } else {
                        $clientId = $cached;
                        $isMatched = true;
                        $matchedCount++;
                    }
                } else {
                    $clientLookup->execute([$custName]);
                    $matches = $clientLookup->fetchAll();
                    if (count($matches) === 1) {
                        $clientId = (int)$matches[0]['id'];
                        $isMatched = true;
                        $matchedCount++;
                        $clientCache[$cacheKey] = $clientId;
                    } elseif (count($matches) === 0) {
                        $clientInsert->execute([
                            barri_truncate(sanitize($custName), 200),
                            '',
                            'Auto-created from ' . $company . ' report',
                        ]);
                        $clientId = sql_last_insert_id($pdo, 'clients');
                        $isMatched = true;
                        $matchedCount++;
                        $createdCount++;
                        $clientCache[$cacheKey] = $clientId;
                    } else {
                        $unmatchedCount++;
                        $clientCache[$cacheKey] = false;
                    }
                }
            }

            $typeMap = ['giros' => 'giros', 'money order' => 'money_order', 'bill payment' => 'bill_payment', 'nueva_orden' => 'nueva_orden', 'cheque_escaneado' => 'cheque_escaneado'];
            $rawType = strtolower(trim($txn['transaction_type'] ?? $txn['type'] ?? 'giros'));
            $txnType = barri_truncate($typeMap[$rawType] ?? $rawType, 30);

            $txnTime = $txn['transaction_time'] ?? $txn['time'] ?? '00:00';
            if ($txnTime === '' || $txnTime === null) $txnTime = '00:00';
            if (strlen($txnTime) === 5) $txnTime .= ':00';

            $principal = (float)($txn['principal'] ?? 0);
            $txnFee = (float)($txn['fee'] ?? 0);
            $txnTax = (float)($txn['tax'] ?? 0);
            $txnTotal = (float)($txn['total'] ?? 0);
            $refNum = barri_truncate(sanitize($txn['reference_number'] ?? $txn['reference'] ?? ''), 30);

            if ($refNum !== '') {
                if ($skipTxnDuplicates && (
                    barri_transaction_ref_exists($pdo, $storeId, $refNum)
                    || isset($importedRefsThisBatch[$refNum])
                )) {
                    $skippedTxnDuplicates++;
                    continue;
                }
                $importedRefsThisBatch[$refNum] = true;
            }

            $beneficiaryName = barri_truncate(sanitize($txn['beneficiary_name'] ?? $txn['beneficiary'] ?? ''), 200);
            $txnDescription = barri_truncate(sanitize($txn['description'] ?? ''), 300);

            $amountReceived = (float)($txn['amount_received'] ?? 0);
            $receivedCurrency = barri_truncate(sanitize($txn['received_currency'] ?? ''), 10);
            $payingBank = barri_truncate(sanitize($txn['paying_bank'] ?? ''), 200);
            $destCountry = barri_truncate(sanitize($txn['destination_country'] ?? ''), 100);
            $destState = barri_truncate(sanitize($txn['destination_state'] ?? ''), 100);
            $destCity = barri_truncate(sanitize($txn['destination_city'] ?? ''), 200);
            $rawPaymentDate = trim((string)($txn['payment_date'] ?? ''));
            $paymentDate = $rawPaymentDate !== '' ? sanitize($rawPaymentDate) : null;
            $txnStatus = barri_truncate(sanitize($txn['transaction_status'] ?? $txn['status'] ?? ''), 50);

            try {
                $txnIns->execute([
                    $reportId, $storeId, $clientId,
                    $txnTime, $txnDate, $txnType,
                    $refNum, barri_truncate(sanitize($custName), 200),
                    $beneficiaryName, $txnDescription,
                    barri_truncate(sanitize($txn['operator'] ?? ''), 30), (int)($txn['quantity'] ?? $txn['qty'] ?? 1),
                    $principal, $txnFee, $txnTax, $txnTotal,
                    (float)($txn['running_balance'] ?? $txn['balance'] ?? 0),
                    (float)($txn['ag_commission'] ?? $txn['agcomm'] ?? 0),
                    (float)($txn['variable_fee'] ?? $txn['var_fee'] ?? 0),
                    (float)($txn['variable_fx'] ?? $txn['var_fx'] ?? 0),
                    db_bool($isMatched),
                    $amountReceived, $receivedCurrency, $payingBank,
                    $destCountry, $destState, $destCity,
                    $paymentDate, $txnStatus
                ]);
            } catch (\Throwable $rowError) {
                throw new RuntimeException(
                    'Failed on transaction #' . ($txnIndex + 1)
                    . ' ref ' . ($refNum !== '' ? $refNum : '(none)')
                    . ': ' . $rowError->getMessage(),
                    0,
                    $rowError
                );
            }
            $barriTxnId = sql_last_insert_id($pdo, 'barri_transactions');

            if (!$isSideTxn && $isMatched && $clientId) {
                $typeLabel = barri_truncate(str_replace('_', ' ', $txnType), 30);
                $dateSent = $txnDate . ' ' . $txnTime;
                $transferBeneficiary = $beneficiaryName ?: barri_truncate(sanitize($custName), 200);
                $transferCompany = barri_truncate($company ?: 'Barri', 50);
                $transferIns->execute([
                    $clientId, $storeId, $refNum,
                    $transferBeneficiary, $dateSent, $principal,
                    $txnFee, $txnTax, $transferCompany, $typeLabel, 'report_import'
                ]);
                $transferId = sql_last_insert_id($pdo, 'transfers');
                $markPushed->execute([$transferId, $barriTxnId]);
                $pushedCount++;
            }
        }

        $pdo->prepare('UPDATE barri_reports SET status = ? WHERE id = ?')->execute(['processed', $reportId]);
        barri_refresh_report_totals($pdo, $reportId);
        if ($isSideFinance) {
            barri_create_side_finance_accounting($pdo, $storeId, $reportId, $data);
        } elseif ($namelessMoneyOrders !== []) {
            barri_create_money_order_side_accounting($pdo, $storeId, $reportId, $data, $namelessMoneyOrders);
        }
        $pdo->commit();

        try {
            require_once __DIR__ . '/../includes/reconciliation.php';
            recon_refresh_report_period($pdo, $storeId, $dateFrom, $dateTo);
        } catch (Throwable $reconErr) {
            error_log('[barri_import_report] reconciliation: ' . $reconErr->getMessage());
        }

        $importedCount = $matchedCount + $unmatchedCount + $sideFinanceCount;

        return [
            'status' => 'success',
            'label' => $sourceLabel,
            'report_id' => $reportId,
            'store_id' => $storeId,
            'unassigned' => $unassigned,
            'auto_matched' => !$unassigned,
            'appended_to_existing' => $appendToExisting,
            'total_transactions' => $importedCount,
            'imported_transaction_count' => $importedCount,
            'matched_count' => $matchedCount,
            'unmatched_count' => $unmatchedCount,
            'side_finance_count' => $sideFinanceCount,
            'finance_class' => $financeClass,
            'data_completeness' => $dataCompleteness,
            'accounting_posted' => $isSideFinance,
            'clients_created' => $createdCount,
            'pushed_to_transfers' => $pushedCount,
            'skipped_transaction_duplicates' => $skippedTxnDuplicates,
            'skipped_in_file_duplicates' => $filterResult['skipped_in_file_duplicates'],
            'skipped_missing_customer' => $skippedMissingCustomer,
        ];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[barri_import_report] ' . $e->getMessage());
        return [
            'status' => 'failed',
            'label' => $sourceLabel,
            'report_id' => null,
            'store_id' => null,
            'unassigned' => false,
            'auto_matched' => false,
            'error' => $e->getMessage(),
        ];
    }
}

function barri_bulk_import_reports(PDO $pdo, array $user, array $reports, array $options = []): array {
    $skipDuplicates = $options['skip_duplicates'] ?? true;
    $defaultExplicitStore = $options['explicit_store'] ?? null;
    $pdfFiles = $options['pdf_files'] ?? [];

    $summary = [
        'success' => 0,
        'failed' => 0,
        'skipped' => 0,
        'unassigned' => 0,
        'results' => [],
    ];

    foreach ($reports as $index => $reportData) {
        if (!is_array($reportData)) {
            $summary['failed']++;
            $summary['results'][] = [
                'index' => $index,
                'status' => 'failed',
                'label' => 'Item ' . ($index + 1),
                'error' => 'Invalid report payload',
            ];
            continue;
        }

        $explicitStore = $defaultExplicitStore;
        if (!empty($reportData['store_id'])) {
            $explicitStore = (int)$reportData['store_id'];
        }

        $pdfFile = null;
        if (isset($pdfFiles[$index])) {
            $pdfFile = $pdfFiles[$index];
        }

        $sourceLabel = $reportData['source_label'] ?? $reportData['original_name'] ?? barri_report_label($reportData);
        $result = barri_import_report($pdo, $user, $reportData, [
            'explicit_store' => $explicitStore,
            'pdf_file' => $pdfFile,
            'skip_duplicates' => $skipDuplicates,
            'skip_duplicate_transactions' => true,
            'source_label' => $sourceLabel,
        ]);
        $result['index'] = $index;

        if ($result['status'] === 'success') {
            $summary['success']++;
            if (!empty($result['unassigned'])) {
                $summary['unassigned']++;
            }
        } elseif ($result['status'] === 'duplicate') {
            $imported = (int)($result['imported_transaction_count'] ?? 0);
            if ($imported > 0) {
                $summary['success']++;
            } else {
                $summary['skipped']++;
            }
        } else {
            $summary['failed']++;
        }

        $summary['results'][] = $result;
    }

    return $summary;
}

function barri_collect_uploaded_pdf_files(): array {
    $files = [];
    if (empty($_FILES)) {
        return $files;
    }

    if (!empty($_FILES['pdf_files'])) {
        $raw = $_FILES['pdf_files'];
        if (is_array($raw['name'])) {
            foreach ($raw['name'] as $i => $name) {
                $files[] = [
                    'name' => $name,
                    'type' => $raw['type'][$i] ?? '',
                    'tmp_name' => $raw['tmp_name'][$i] ?? '',
                    'error' => $raw['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $raw['size'][$i] ?? 0,
                ];
            }
        } else {
            $files[] = $raw;
        }
    }

    foreach ($_FILES as $key => $raw) {
        if (!str_starts_with($key, 'pdf_file_')) continue;
        if (is_array($raw['name'])) {
            foreach ($raw['name'] as $i => $name) {
                $files[] = [
                    'name' => $name,
                    'type' => $raw['type'][$i] ?? '',
                    'tmp_name' => $raw['tmp_name'][$i] ?? '',
                    'error' => $raw['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $raw['size'][$i] ?? 0,
                ];
            }
        } else {
            $files[] = $raw;
        }
    }

    return $files;
}

if ($method === 'GET') {
    // Download original PDF
    if (!empty($_GET['download'])) {
        $stmt = $pdo->prepare('SELECT store_id, filename, original_name FROM barri_reports WHERE id = ?');
        $stmt->execute([(int)$_GET['download']]);
        $r = $stmt->fetch();
        if (!$r || !$r['filename']) json_error('PDF not available for this report', 404);
        auth_require_store_access((int)$r['store_id']);
        storage_serve($r['filename'], [
            'content_type' => 'application/pdf',
            'download_name' => $r['original_name'] ?: 'report.pdf',
        ]);
    }

    $id = $_GET['id'] ?? null;

    if ($id) {
        $stmt = $pdo->prepare('SELECT *, report_date_from as date_from, report_date_to as date_to FROM barri_reports WHERE id = ?');
        $stmt->execute([(int)$id]);
        $report = $stmt->fetch();
        if (!$report) json_error('Report not found', 404);
        auth_require_store_access((int)$report['store_id']);

        $txns = $pdo->prepare('SELECT bt.*, bt.transaction_time as time, bt.transaction_type as type, bt.reference_number as reference, bt.ag_commission as agcomm, bt.variable_fee as var_fee, bt.variable_fx as var_fx, bt.amount_received, bt.received_currency, bt.paying_bank, bt.destination_country, bt.destination_state, bt.destination_city, bt.payment_date, bt.transaction_status, c.name as client_name, c.phone as client_phone FROM barri_transactions bt LEFT JOIN clients c ON c.id = bt.client_id WHERE bt.report_id = ? ORDER BY bt.transaction_date, bt.transaction_time');
        $txns->execute([(int)$id]);
        $transactions = $txns->fetchAll();

        $total = count($transactions);
        $matched = 0;
        $pushed = 0;
        foreach ($transactions as $t) {
            if ($t['matched']) $matched++;
            if ($t['pushed_to_transfers']) $pushed++;
        }

        json_response([
            'report' => $report,
            'transactions' => $transactions,
            'match_stats' => [
                'total' => $total,
                'matched' => $matched,
                'unmatched' => $total - $matched,
                'pushed' => $pushed,
            ],
        ]);
    }

    $requestedStore = !empty($_GET['store_id']) ? (int)$_GET['store_id'] : null;
    if (auth_is_admin() && !$requestedStore) {
        $stmt = $pdo->query('SELECT br.*, br.report_date_from as date_from, br.report_date_to as date_to, br.company, br.report_type, u.name as user_name, (SELECT COUNT(*) FROM barri_transactions bt WHERE bt.report_id = br.id AND ' . sql_is_true('bt.matched') . ') as matched_count, (SELECT COUNT(*) FROM barri_transactions bt WHERE bt.report_id = br.id AND ' . sql_is_true('bt.pushed_to_transfers') . ') as pushed_count FROM barri_reports br LEFT JOIN users u ON u.id = br.user_id ORDER BY br.report_date_from DESC LIMIT 200');
        json_response(['reports' => $stmt->fetchAll()]);
    }
    $storeId = resolve_store_id($requestedStore);
    $stmt = $pdo->prepare('SELECT br.*, br.report_date_from as date_from, br.report_date_to as date_to, br.company, br.report_type, u.name as user_name, (SELECT COUNT(*) FROM barri_transactions bt WHERE bt.report_id = br.id AND ' . sql_is_true('bt.matched') . ') as matched_count, (SELECT COUNT(*) FROM barri_transactions bt WHERE bt.report_id = br.id AND ' . sql_is_true('bt.pushed_to_transfers') . ') as pushed_count FROM barri_reports br LEFT JOIN users u ON u.id = br.user_id WHERE br.store_id = ? ORDER BY br.report_date_from DESC LIMIT 100');
    $stmt->execute([$storeId]);
    json_response(['reports' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    csrf_verify();

    // Handle multipart import (PDF file + JSON data)
    if (!empty($_POST['action']) && $_POST['action'] === 'import') {
        set_time_limit(300);
        $data = json_decode($_POST['data'] ?? '{}', true) ?: [];
        if (empty($data)) json_error('No report data received. The payload may be too large.');

        $explicitStore = null;
        if (!empty($_POST['store_id'])) {
            $explicitStore = (int)$_POST['store_id'];
        }
        if (!empty($data['store_id'])) {
            $explicitStore = (int)$data['store_id'];
        }
        if (!auth_is_admin()) {
            $explicitStore = null;
        }

        $pdfFile = (!empty($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) ? $_FILES['pdf_file'] : null;
        $skipTxnDupes = !isset($_POST['skip_duplicate_transactions']) || $_POST['skip_duplicate_transactions'] !== '0';
        $result = barri_import_report($pdo, $user, $data, [
            'explicit_store' => $explicitStore,
            'pdf_file' => $pdfFile,
            'skip_duplicates' => true,
            'skip_duplicate_transactions' => $skipTxnDupes,
        ]);

        if ($result['status'] === 'duplicate') {
            json_response([
                'success' => true,
                'duplicate' => true,
                'report_id' => $result['report_id'],
                'store_id' => $result['store_id'],
                'unassigned' => $result['unassigned'] ?? false,
                'imported_transaction_count' => $result['imported_transaction_count'] ?? 0,
                'skipped_transaction_duplicates' => $result['skipped_transaction_duplicates'] ?? 0,
                'skipped_in_file_duplicates' => $result['skipped_in_file_duplicates'] ?? 0,
                'message' => $result['error'] ?? 'Duplicate report skipped',
            ]);
        }
        if ($result['status'] === 'failed') {
            json_error($result['error'] ?? 'Import failed', 400);
        }

        json_response([
            'success' => true,
            'report_id' => $result['report_id'],
            'total_transactions' => $result['total_transactions'],
            'imported_transaction_count' => $result['imported_transaction_count'] ?? $result['total_transactions'],
            'matched_count' => $result['matched_count'],
            'unmatched_count' => $result['unmatched_count'],
            'clients_created' => $result['clients_created'],
            'pushed_to_transfers' => $result['pushed_to_transfers'],
            'unassigned' => $result['unassigned'],
            'skipped_transaction_duplicates' => $result['skipped_transaction_duplicates'] ?? 0,
            'skipped_in_file_duplicates' => $result['skipped_in_file_duplicates'] ?? 0,
            'appended_to_existing' => $result['appended_to_existing'] ?? false,
        ], 201);
    }

    if (!empty($_POST['action']) && $_POST['action'] === 'bulk_import') {
        auth_require_admin();
        set_time_limit(600);

        $reports = json_decode($_POST['data'] ?? $_POST['reports'] ?? '[]', true);
        if (!is_array($reports) || count($reports) === 0) {
            json_error('No report payloads received for bulk import', 400);
        }

        $explicitStore = !empty($_POST['store_id']) ? (int)$_POST['store_id'] : null;
        $skipDuplicates = !isset($_POST['skip_duplicates']) || $_POST['skip_duplicates'] !== '0';

        $summary = barri_bulk_import_reports($pdo, $user, $reports, [
            'explicit_store' => $explicitStore,
            'skip_duplicates' => $skipDuplicates,
            'pdf_files' => barri_collect_uploaded_pdf_files(),
        ]);

        json_response($summary);
    }

    // All other POST actions use JSON body
    $data = get_json_body();
    $act = $data['action'] ?? '';

    if ($act === 'bulk_import') {
        auth_require_admin();
        set_time_limit(600);

        $reports = $data['reports'] ?? [];
        if (!is_array($reports) || count($reports) === 0) {
            json_error('reports array is required for bulk import', 400);
        }

        $explicitStore = !empty($data['store_id']) ? (int)$data['store_id'] : null;
        $skipDuplicates = !array_key_exists('skip_duplicates', $data) || (bool)$data['skip_duplicates'];

        $summary = barri_bulk_import_reports($pdo, $user, $reports, [
            'explicit_store' => $explicitStore,
            'skip_duplicates' => $skipDuplicates,
        ]);

        json_response($summary);
    }

    $requestedStore = !empty($data['store_id']) ? (int)$data['store_id'] : null;
    $storeId = resolve_store_id($requestedStore);

    if ($act === 'check_import') {
        $parsed = $data['parsed'] ?? $data;
        if (!is_array($parsed)) {
            json_error('parsed report data is required', 400);
        }
        $parsed = barri_normalize_report_data($parsed);

        $explicitStore = $requestedStore;
        if (!auth_is_admin()) {
            $explicitStore = null;
        }
        $storeCtx = barri_resolve_import_store_id($pdo, $user, $parsed, $explicitStore);
        $storeId = $storeCtx['store_id'];

        $dateFrom = $parsed['report_date_from'];
        $dateTo = $parsed['report_date_to'];
        $agencyNum = sanitize($parsed['agency_number'] ?? '');

        $reportDuplicate = null;
        $dupStmt = $pdo->prepare(
            'SELECT id, agency_name, agency_number, report_date_from, report_date_to, total_transactions, created_at
             FROM barri_reports WHERE store_id = ? AND agency_number = ? AND report_date_from = ? AND report_date_to = ?'
        );
        $dupStmt->execute([$storeId, $agencyNum, $dateFrom, $dateTo]);
        $existingReport = $dupStmt->fetch();
        if ($existingReport) {
            $reportDuplicate = $existingReport;
        }

        $refs = [];
        foreach ($parsed['transactions'] ?? [] as $txn) {
            $r = trim($txn['reference_number'] ?? $txn['reference'] ?? '');
            if ($r !== '') {
                $refs[] = $r;
            }
        }
        $duplicateTransactions = barri_find_duplicate_transactions_detail($pdo, $storeId, $refs);
        $dupRefSet = array_flip(array_column($duplicateTransactions, 'reference'));
        $newCount = 0;
        foreach ($parsed['transactions'] ?? [] as $txn) {
            $r = trim($txn['reference_number'] ?? $txn['reference'] ?? '');
            if ($r === '' || !isset($dupRefSet[$r])) {
                $newCount++;
            }
        }

        json_response([
            'store_id' => $storeId,
            'unassigned' => $storeCtx['unassigned'],
            'report_duplicate' => $reportDuplicate,
            'duplicate_transactions' => $duplicateTransactions,
            'duplicate_transaction_count' => count($duplicateTransactions),
            'new_transaction_count' => $newCount,
        ]);
    }

    if ($act === 'match') {
        validate_required($data, ['transaction_id', 'client_id']);
        $pdo->prepare('UPDATE barri_transactions SET client_id = ?, matched = ' . sql_bool(true) . ' WHERE id = ? AND store_id = ?')
            ->execute([(int)$data['client_id'], (int)$data['transaction_id'], $storeId]);
        json_response(['success' => true]);
    }

    if ($act === 'unmatch') {
        validate_required($data, ['transaction_id']);
        $pdo->prepare('UPDATE barri_transactions SET client_id = NULL, matched = ' . sql_bool(false) . ' WHERE id = ? AND store_id = ?')
            ->execute([(int)$data['transaction_id'], $storeId]);
        json_response(['success' => true]);
    }

    if ($act === 'create_client_and_match') {
        validate_required($data, ['transaction_id']);
        $txn = $pdo->prepare('SELECT * FROM barri_transactions WHERE id = ? AND store_id = ?');
        $txn->execute([(int)$data['transaction_id'], $storeId]);
        $txnRow = $txn->fetch();
        if (!$txnRow) json_error('Transaction not found', 404);

        $pdo->prepare('INSERT INTO clients (name, phone, monthly_limit) VALUES (?,?,3000)')
            ->execute([sanitize($txnRow['customer_name']), sanitize($data['phone'] ?? '')]);
        $newClientId = sql_last_insert_id($pdo, 'clients');

        $pdo->prepare('UPDATE barri_transactions SET client_id = ?, matched = ' . sql_bool(true) . ' WHERE id = ?')
            ->execute([$newClientId, (int)$data['transaction_id']]);

        json_response(['success' => true, 'client_id' => $newClientId]);
    }

    if ($act === 'search_clients') {
        $q = '%' . ($data['query'] ?? '') . '%';
        $stmt = $pdo->prepare(
            'SELECT DISTINCT c.id, c.name, c.phone FROM clients c
             INNER JOIN transfers t ON t.client_id = c.id AND t.store_id = ?
             WHERE c.name LIKE ? ORDER BY c.name LIMIT 20'
        );
        $stmt->execute([$storeId, $q]);
        json_response(['clients' => $stmt->fetchAll()]);
    }

    if ($act === 'push_to_transfers') {
        validate_required($data, ['report_id']);
        $reportId = (int)$data['report_id'];

        $report = $pdo->prepare('SELECT * FROM barri_reports WHERE id = ? AND store_id = ?');
        $report->execute([$reportId, $storeId]);
        $reportRow = $report->fetch();
        if (!$reportRow) json_error('Report not found', 404);
        $reportCompany = $reportRow['company'] ?? 'Barri';

        $txns = $pdo->prepare('SELECT * FROM barri_transactions WHERE report_id = ? AND ' . sql_is_true('matched') . ' AND ' . sql_is_false('pushed_to_transfers'));
        $txns->execute([$reportId]);
        $rows = $txns->fetchAll();

        $transferIns = $pdo->prepare('INSERT INTO transfers (client_id, store_id, transaction_code, beneficiary, date_sent, amount_usd, fee, tax, company, transaction_type, source) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $markPushed = $pdo->prepare('UPDATE barri_transactions SET pushed_to_transfers = ' . sql_bool(true) . ', transfer_id = ? WHERE id = ?');

        $pushedCount = 0;
        foreach ($rows as $row) {
            $typeLabel = str_replace('_', ' ', $row['transaction_type']);
            $dateSent = $row['transaction_date'] . ' ' . $row['transaction_time'];
            $beneficiary = $row['beneficiary_name'] ?: $row['customer_name'];
            $transferIns->execute([
                $row['client_id'], $storeId, $row['reference_number'],
                $beneficiary, $dateSent, $row['principal'],
                $row['fee'], $row['tax'], $reportCompany, $typeLabel, 'report_import'
            ]);
            $transferId = sql_last_insert_id($pdo, 'transfers');
            $markPushed->execute([$transferId, $row['id']]);
            $pushedCount++;
        }

        json_response(['success' => true, 'pushed_count' => $pushedCount]);
    }

    if ($act === 'delete') {
        json_error('Uploaded reports are retained and cannot be deleted.', 403);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
