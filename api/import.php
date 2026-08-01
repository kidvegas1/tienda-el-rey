<?php
$user = auth_require();
if (!auth_can_import_excel()) {
    json_error('Manager or admin access required', 403);
}
$method = get_method();
$pdo = db();
require_once __DIR__ . '/../includes/reconciliation.php';
require_once __DIR__ . '/../includes/commission.php';

function import_store_id(?array $data = null): int {
    $requested = null;
    if (!empty($_GET['store_id'])) {
        $requested = (int)$_GET['store_id'];
    } elseif (!empty($_POST['store_id'])) {
        $requested = (int)$_POST['store_id'];
    } elseif ($data !== null && !empty($data['store_id'])) {
        $requested = (int)$data['store_id'];
    }
    return resolve_store_id($requested);
}

function parseMonthSpanish(string $m): int {
    $map = ['ene'=>1,'enero'=>1,'feb'=>2,'febrero'=>2,'mar'=>3,'marzo'=>3,'abr'=>4,'abril'=>4,
            'may'=>5,'mayo'=>5,'jun'=>6,'junio'=>6,'jul'=>7,'julio'=>7,'ago'=>8,'agosto'=>8,'agost'=>8,
            'sep'=>9,'sept'=>9,'septiembre'=>9,'oct'=>10,'octubre'=>10,'nov'=>11,'noviembre'=>11,
            'dic'=>12,'diciembre'=>12,'eneero'=>1];
    return $map[strtolower(trim($m))] ?? (int)$m;
}

/**
 * @return array{rows:int,report_id:int,agency_number:string,date_from:string,date_to:string,volume:float,commission:float}
 */
function import_viamericas_check_cashing(
    PDO $pdo,
    int $storeId,
    int $userId,
    array $rows,
    array $importRow
): array {
    $checks = [];
    foreach ($rows as $row) {
        $status = trim((string)($row['status'] ?? ''));
        if ($status !== '' && strcasecmp($status, 'Enviado') !== 0) continue;

        $amount = abs((float)($row['amount'] ?? 0));
        $date = trim((string)($row['transaction_date'] ?? ''));
        $time = trim((string)($row['transaction_time'] ?? '00:00:00'));
        $agency = strtoupper(sanitize(trim((string)($row['agency_number'] ?? ''))));
        $reference = sanitize(trim((string)($row['reference'] ?? '')));
        if ($amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $reference === '') continue;
        if ($agency === '' || !preg_match('/^A\d{4,}$/', $agency)) continue;

        $rate = commission_rate_for_check($amount);
        $checks[] = [
            'agency_number' => $agency,
            'date' => $date,
            'time' => preg_match('/^\d{2}:\d{2}:\d{2}$/', $time) ? $time : '00:00:00',
            'reference' => substr($reference, 0, 30),
            'customer' => sanitize(trim((string)($row['customer_name'] ?? ''))),
            'issuer' => sanitize(trim((string)($row['issuer'] ?? ''))),
            'operator' => sanitize(trim((string)($row['operator'] ?? $agency))),
            'amount' => round($amount, 2),
            'commission' => round($amount * $rate, 2),
            'rate' => $rate,
        ];
    }
    if (!$checks) {
        throw new RuntimeException('No valid Viamericas check-cashing rows were found');
    }

    usort($checks, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']));
    $agencyNumber = $checks[0]['agency_number'];
    $dateFrom = $checks[0]['date'];
    $dateTo = $checks[count($checks) - 1]['date'];

    $dup = $pdo->prepare(
        "SELECT id FROM barri_reports
         WHERE store_id = ? AND agency_number = ? AND report_date_from = ? AND report_date_to = ?
           AND report_type = 'viamericas_check_cashing'
         LIMIT 1"
    );
    $dup->execute([$storeId, $agencyNumber, $dateFrom, $dateTo]);
    if ($dup->fetch()) {
        throw new RuntimeException('This Viamericas check-cashing report has already been imported for this store and period');
    }

    $totalAmount = round(array_sum(array_column($checks, 'amount')), 2);
    $totalCommission = round(array_sum(array_column($checks, 'commission')), 2);
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO barri_reports
             (store_id, user_id, agency_number, agency_name, company, report_date_from, report_date_to,
              total_transactions, total_principal, total_amount, total_agcomm, filename, original_name,
              status, report_type)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $storeId, $userId, $agencyNumber, 'Viamericas Check Cashing', 'Viamericas',
            $dateFrom, $dateTo, count($checks), $totalAmount, $totalAmount, $totalCommission,
            '', (string)($importRow['original_name'] ?? 'organization.xlsx'),
            'processed', 'viamericas_check_cashing',
        ]);
        $reportId = sql_last_insert_id($pdo, 'barri_reports');

        $insert = $pdo->prepare(
            'INSERT INTO barri_transactions
             (report_id, store_id, transaction_time, transaction_date, transaction_type,
              reference_number, customer_name, description, operator, quantity, principal,
              fee, tax, total, running_balance, ag_commission, variable_fee, variable_fx,
              transaction_status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $seen = [];
        foreach ($checks as $check) {
            if (isset($seen[$check['reference']])) continue;
            $seen[$check['reference']] = true;
            $insert->execute([
                $reportId, $storeId, $check['time'], $check['date'], 'cambio_cheque',
                $check['reference'], $check['customer'] ?: 'Unknown customer',
                $check['issuer'], $check['operator'], 1, $check['amount'],
                0, 0, $check['amount'], 0, $check['commission'], 0, 0, 'Enviado',
            ]);
        }
        $pdo->commit();
        return [
            'rows' => count($seen),
            'report_id' => (int)$reportId,
            'agency_number' => $agencyNumber,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'volume' => $totalAmount,
            'commission' => $totalCommission,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function import_module_from_mapping(mixed $mapping): string {
    if (is_string($mapping) && $mapping !== '') {
        $decoded = json_decode($mapping, true);
        $mapping = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($mapping)) {
        return '';
    }
    foreach ($mapping as $key => $value) {
        if ($key === '_summary' || $key === '_meta') {
            continue;
        }
        $module = is_array($value) ? (string)($value['module'] ?? '') : (string)$value;
        if ($module !== '' && $module !== 'skip') {
            return $module;
        }
    }
    return '';
}

if ($method === 'GET') {
    $storeId = import_store_id();
    $stmt = $pdo->prepare(
        'SELECT ei.*, u.name as user_name, s.name as store_name
         FROM excel_imports ei
         LEFT JOIN users u ON u.id = ei.user_id
         LEFT JOIN stores s ON s.id = ei.store_id
         WHERE ei.store_id = ?
         ORDER BY ei.imported_at DESC
         LIMIT 50'
    );
    $stmt->execute([$storeId]);
    $imports = $stmt->fetchAll();

    $reportLookup = $pdo->prepare(
        "SELECT id, agency_number, report_date_from, report_date_to, total_transactions,
                total_principal, total_agcomm, report_type, store_id
         FROM barri_reports
         WHERE store_id = ? AND original_name = ? AND report_type = 'viamericas_check_cashing'
         ORDER BY id DESC
         LIMIT 1"
    );

    foreach ($imports as &$imp) {
        $mapping = $imp['sheet_mapping'] ?? null;
        if (is_string($mapping) && $mapping !== '') {
            $decoded = json_decode($mapping, true);
            $mapping = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($mapping)) {
            $mapping = [];
        }
        $summary = is_array($mapping['_summary'] ?? null) ? $mapping['_summary'] : [];
        $module = import_module_from_mapping($mapping);
        $displayName = trim((string)($imp['original_name'] ?? ''));
        if ($displayName === '') {
            $displayName = basename((string)($imp['filename'] ?? ''));
        }

        $report = null;
        if (!empty($summary['report_id'])) {
            $reportId = (int)$summary['report_id'];
            $rStmt = $pdo->prepare(
                'SELECT id, agency_number, report_date_from, report_date_to, total_transactions,
                        total_principal, total_agcomm, report_type, store_id
                 FROM barri_reports WHERE id = ? LIMIT 1'
            );
            $rStmt->execute([$reportId]);
            $report = $rStmt->fetch() ?: null;
        } elseif ($module === 'cambio_cheques' && $displayName !== '') {
            $reportLookup->execute([(int)$imp['store_id'], $displayName]);
            $report = $reportLookup->fetch() ?: null;
        }

        $imp['display_name'] = $displayName;
        $imp['module'] = $module;
        $imp['created_at'] = $imp['imported_at'] ?? null;
        $imp['summary'] = [
            'report_id' => $report ? (int)$report['id'] : (int)($summary['report_id'] ?? 0),
            'agency_number' => (string)($report['agency_number'] ?? $summary['agency_number'] ?? ''),
            'date_from' => (string)($report['report_date_from'] ?? $summary['date_from'] ?? ''),
            'date_to' => (string)($report['report_date_to'] ?? $summary['date_to'] ?? ''),
            'volume' => (float)($report['total_principal'] ?? $summary['volume'] ?? 0),
            'commission' => (float)($report['total_agcomm'] ?? $summary['commission'] ?? 0),
            'rows' => (int)($report['total_transactions'] ?? $summary['rows'] ?? $imp['rows_imported'] ?? 0),
            'report_type' => (string)($report['report_type'] ?? $summary['report_type'] ?? ''),
        ];
    }
    unset($imp);

    json_response(['imports' => $imports]);
}

if ($method === 'POST') {
    csrf_verify();
    $storeId = import_store_id();

    if (!empty($_FILES['file'])) {
        $file = $_FILES['file'];
        $allowed = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'];
        if (!in_array($file['type'], $allowed) && !str_ends_with($file['name'], '.xlsx') && !str_ends_with($file['name'], '.xls')) {
            json_error('Only Excel files (.xlsx, .xls) are allowed');
        }

        $path = upload_file($file, 'imports');
        if (!$path) json_error('Failed to upload file');

        $stmt = $pdo->prepare('INSERT INTO excel_imports (store_id, user_id, filename, original_name, status) VALUES (?,?,?,?,?)');
        $stmt->execute([$storeId, $user['id'], $path, $file['name'], 'pending']);

        json_response(['success' => true, 'import_id' => sql_last_insert_id($pdo, 'excel_imports'), 'filename' => $file['name']], 201);
    }

    $data = get_json_body();
    $act = $data['action'] ?? '';

    if ($act === 'prepare_import') {
        validate_required($data, ['original_name']);
        $originalName = sanitize((string)$data['original_name']);
        if ($originalName === '') {
            json_error('Original file name is required');
        }
        $stmt = $pdo->prepare('INSERT INTO excel_imports (store_id, user_id, filename, original_name, status) VALUES (?,?,?,?,?)');
        $stmt->execute([$storeId, $user['id'], $originalName, $originalName, 'pending']);
        json_response([
            'success'   => true,
            'import_id' => sql_last_insert_id($pdo, 'excel_imports'),
            'filename'  => $originalName,
        ], 201);
    }

    if ($act === 'confirm_import') {
        validate_required($data, ['import_id', 'sheet_mapping']);
        $importId = (int)$data['import_id'];

        $impStmt = $pdo->prepare('SELECT store_id, user_id, filename, original_name FROM excel_imports WHERE id = ?');
        $impStmt->execute([$importId]);
        $impRow = $impStmt->fetch();
        if (!$impRow) {
            json_error('Import not found', 404);
        }
        auth_require_store_access((int)$impRow['store_id']);
        $storeId = (int)$impRow['store_id'];

        $rowsImported = 0;
        $errors = [];
        $importedCaja = false;
        $importSummary = [];

        // Support both formats: { rows: { module: [...] } } and { sheets: { sheetName: { module, rows } } }
        $moduleRows = [];
        if (!empty($data['sheets'])) {
            foreach ($data['sheets'] as $sheetName => $info) {
                $mod = $info['module'] ?? 'skip';
                if ($mod === 'skip') continue;
                if (!isset($moduleRows[$mod])) $moduleRows[$mod] = [];
                $moduleRows[$mod] = array_merge($moduleRows[$mod], $info['rows'] ?? []);
            }
        } elseif (!empty($data['rows'])) {
            $moduleRows = $data['rows'];
        }

        foreach ($moduleRows as $module => $rows) {
            if ($module === 'cambio_cheques') {
                try {
                    $checkResult = import_viamericas_check_cashing(
                        $pdo,
                        $storeId,
                        (int)$user['id'],
                        is_array($rows) ? $rows : [],
                        $impRow
                    );
                    $rowsImported += (int)$checkResult['rows'];
                    $importSummary = array_merge($importSummary, $checkResult, [
                        'module' => 'cambio_cheques',
                        'report_type' => 'viamericas_check_cashing',
                    ]);
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
                continue;
            }
            foreach ($rows as $row) {
                try {
                    if ($module === 'caja') {
                        $vals = array_values($row);
                        $company = sanitize(trim((string)($vals[0] ?? '')));
                        if (!$company || strtolower($company) === 'compania' || strtolower($company) === 'total') continue;
                        $income = (float)($vals[1] ?? 0);
                        $checksDebits = (float)($vals[2] ?? 0);
                        $total = (float)($vals[3] ?? 0);
                        if ($income == 0 && $checksDebits == 0 && $total == 0) continue;

                        // Find or create today's caja session
                        $today = date('Y-m-d');
                        $sess = $pdo->prepare('SELECT id FROM caja_sessions WHERE store_id = ? AND session_date = ? LIMIT 1');
                        $sess->execute([$storeId, $today]);
                        $sessRow = $sess->fetch();
                        if (!$sessRow) {
                            $pdo->prepare('INSERT INTO caja_sessions (store_id, user_id, session_date, opening_balance, status) VALUES (?,?,?,0,?)')
                                ->execute([$storeId, $user['id'], $today, 'open']);
                            $sessionId = sql_last_insert_id($pdo, 'caja_sessions');
                        } else {
                            $sessionId = (int)$sessRow['id'];
                        }

                        $pdo->prepare('INSERT INTO caja_entries (session_id, company, cash_in, checks_debits, notes) VALUES (?,?,?,?,?)')
                            ->execute([$sessionId, $company, $income, $checksDebits, 'Imported from Excel']);
                        $rowsImported++;
                        $importedCaja = true;
                    }
                    if ($module === 'clients') {
                        $name = sanitize($row['CLIENTE'] ?? $row['name'] ?? '');
                        if (!$name) continue;
                        $check = $pdo->prepare('SELECT id FROM clients WHERE name = ? LIMIT 1');
                        $check->execute([$name]);
                        if (!$check->fetch()) {
                            $code = sanitize($row[' '] ?? $row['client_code'] ?? '');
                            $phone = sanitize($row['TELEFONO'] ?? $row['phone'] ?? '');
                            $pdo->prepare('INSERT INTO clients (client_code, name, phone, monthly_limit) VALUES (?,?,?,5000)')
                                ->execute([$code, $name, $phone]);
                        }
                        $rowsImported++;
                    }
                    if ($module === 'inventory') {
                        $prodName = sanitize($row['PRODUCTO'] ?? $row['product_name'] ?? '');
                        if (!$prodName) continue;
                        $pdo->prepare('INSERT INTO inventory (store_id, product_name, quantity, description, cost_price, retail_price) VALUES (?,?,?,?,?,?)')
                            ->execute([
                                $storeId, $prodName,
                                (int)($row['QTY'] ?? $row['quantity'] ?? 0),
                                sanitize($row['PARA QUE SIRVE'] ?? $row['description'] ?? ''),
                                (float)($row['PRECIO'] ?? $row['cost_price'] ?? 0),
                                (float)($row['PRECIO PUBLICO'] ?? $row['retail_price'] ?? 0),
                            ]);
                        $rowsImported++;
                    }
                    if ($module === 'events') {
                        $clientName = sanitize($row['NOMBRE DE CLIENTE'] ?? $row['client_name'] ?? '');
                        if (!$clientName) continue;
                        $pdo->prepare('INSERT INTO events (store_id, client_name, phone, event_date, deposit, balance, color_theme, status) VALUES (?,?,?,?,?,?,?,?)')
                            ->execute([
                                $storeId, $clientName,
                                sanitize($row['TELEFONO'] ?? $row['phone'] ?? ''),
                                $row['FECHA DE EVENTO'] ?? $row['event_date'] ?? date('Y-m-d'),
                                (float)($row['ANTICIPO'] ?? $row['deposit'] ?? 0),
                                (float)($row['BALANCE'] ?? $row['balance'] ?? 0),
                                sanitize($row['TEMA COLOR'] ?? $row['color_theme'] ?? ''),
                                'booked',
                            ]);
                        $rowsImported++;
                    }
                    if ($module === 'statistics') {
                        // ESTADISTICAS GIROS: rows have company transfer counts per month
                        // Each row has values like: barri_count, month_name, via_count, month_name, ...
                        // We store into transfer_statistics
                        $vals = array_values($row);
                        $companies = ['Barri', 'Via', 'Inter', 'Lake June', 'Dinex'];
                        $colPairs = [[0,1],[4,5],[8,9],[11,11],[12,13]]; // [count_col, month_col]
                        $monthStr = '';
                        foreach ($colPairs as $ci => [$countCol, $monthCol]) {
                            $count = (int)($vals[$countCol] ?? 0);
                            if ($count <= 0) continue;
                            $monthStr = trim((string)($vals[$monthCol] ?? ''));
                            if (!$monthStr || is_numeric($monthStr)) continue;
                            $company = $companies[$ci] ?? 'Other';
                            $monthNum = parseMonthSpanish($monthStr);
                            $year = (int)date('Y');
                            $pdo->prepare(sql_upsert(
                                'transfer_statistics',
                                ['store_id', 'company', 'month', 'year', 'transfer_count', 'total_usd'],
                                ['transfer_count'],
                                ['store_id', 'company', 'month', 'year']
                            ))
                                ->execute([$storeId, $company, $monthNum, $year, $count, 0]);
                            $rowsImported++;
                        }
                    }
                    if ($module === 'internal_ledger') {
                        $vals = array_values($row);
                        $descLeft = trim((string)($vals[0] ?? ''));
                        $amtLeft = (float)($vals[1] ?? 0);
                        $descRight = trim((string)($vals[3] ?? ''));
                        $amtRight = (float)($vals[4] ?? 0);

                        if ($descLeft && $amtLeft > 0 && !in_array(strtolower($descLeft), ['total','-','le debo a suly','le debo a suly '])) {
                            $pdo->prepare('INSERT INTO internal_ledger (store_id, employee_name, description, owed_to_store, store_owes, entry_date, entry_source) VALUES (?,?,?,?,0,' . sql_curdate() . ',?)')
                                ->execute([$storeId, '', sanitize($descLeft), $amtLeft, 'manual']);
                            $rowsImported++;
                        }
                        if ($descRight && $amtRight > 0 && !in_array(strtolower($descRight), ['total','suly me debe','suly me debe '])) {
                            $pdo->prepare('INSERT INTO internal_ledger (store_id, employee_name, description, owed_to_store, store_owes, entry_date, entry_source) VALUES (?,?,?,0,?,' . sql_curdate() . ',?)')
                                ->execute([$storeId, '', sanitize($descRight), $amtRight, 'manual']);
                            $rowsImported++;
                        }
                    }
                    if ($module === 'accounting') {
                        $vals = array_values($row);
                        $desc = sanitize(trim((string)($vals[0] ?? '')));
                        $amount = (float)($vals[1] ?? 0);
                        if (!$desc || $amount == 0) continue;
                        $entryType = $amount >= 0 ? 'receivable' : 'payable';
                        $pdo->prepare('INSERT INTO accounting_entries (store_id, description, amount, entry_type, entry_date, notes) VALUES (?,?,?,?, ' . sql_curdate() . ',?)')
                            ->execute([$storeId, $desc, abs($amount), $entryType, 'Imported from Excel']);
                        $rowsImported++;
                    }
                    if ($module === 'transfers') {
                        $sender = sanitize(trim((string)($row['sender_name'] ?? $row['client_name'] ?? $row['CLIENTE'] ?? '')));
                        $beneficiary = sanitize(trim((string)($row['beneficiary'] ?? $row['BENEFICIARIO'] ?? '')));
                        $ref = sanitize(trim((string)($row['reference'] ?? $row['transaction_code'] ?? $row['REFERENCIA'] ?? '')));
                        $amountUsd = (float)($row['amount_usd'] ?? $row['PRINCIPAL (USD)'] ?? 0);
                        $amountLocal = (float)($row['amount_local'] ?? 0);
                        $fee = (float)($row['fee'] ?? $row['FEE'] ?? 0);
                        $tax = (float)($row['tax'] ?? $row['TAX'] ?? 0);
                        if (!$sender && !$beneficiary) continue;
                        if ($amountUsd == 0 && $amountLocal == 0) continue;

                        $clientId = null;
                        if ($sender) {
                            $check = $pdo->prepare('SELECT id FROM clients WHERE name = ? LIMIT 1');
                            $check->execute([$sender]);
                            $clientRow = $check->fetch();
                            if ($clientRow) {
                                $clientId = (int)$clientRow['id'];
                            } else {
                                $pdo->prepare('INSERT INTO clients (client_code, name, phone, monthly_limit) VALUES (?,?,?,5000)')
                                    ->execute(['', $sender, '']);
                                $clientId = (int)sql_last_insert_id($pdo, 'clients');
                            }
                        }
                        if (!$clientId) continue;

                        if ($ref) {
                            $dup = $pdo->prepare('SELECT id FROM transfers WHERE store_id = ? AND transaction_code = ? LIMIT 1');
                            $dup->execute([$storeId, $ref]);
                            if ($dup->fetch()) continue;
                        }

                        $dateSent = trim((string)($row['date_sent'] ?? $row['FECHA'] ?? ''));
                        if (!$dateSent) $dateSent = date('Y-m-d H:i:s');
                        $datePaid = trim((string)($row['date_paid'] ?? ''));
                        if ($datePaid === '') $datePaid = null;

                        $company = sanitize(trim((string)($row['company'] ?? $row['COMPANIA'] ?? 'Viamericas')));
                        $txnType = sanitize(trim((string)($row['transaction_type'] ?? $row['TIPO'] ?? 'money_transfer')));
                        $source = sanitize(trim((string)($row['source'] ?? 'excel_import')));
                        $bank = sanitize(trim((string)($row['paying_bank'] ?? '')));
                        $destCountry = sanitize(trim((string)($row['destination_country'] ?? '')));
                        $destCity = sanitize(trim((string)($row['destination_city'] ?? '')));
                        $currency = sanitize(trim((string)($row['currency'] ?? 'MXN')));

                        $pdo->prepare('INSERT INTO transfers (client_id, store_id, transaction_code, beneficiary, date_sent, date_paid, amount_usd, fee, tax, amount_local, currency, paying_bank, destination_country, destination_city, company, transaction_type, source) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                            ->execute([
                                $clientId, $storeId, $ref ?: null, $beneficiary ?: $sender,
                                $dateSent, $datePaid, $amountUsd, $fee ?: null, $tax ?: null,
                                $amountLocal ?: null, $currency, $bank ?: null,
                                $destCountry ?: null, $destCity ?: null,
                                $company, $txnType, $source,
                            ]);
                        $rowsImported++;
                    }
                    if ($module === 'plates') {
                        $clientName = sanitize($row['CLIENTE'] ?? $row['CLIENTE '] ?? '');
                        if (!$clientName) { $vals = array_values($row); $clientName = sanitize(trim((string)($vals[0] ?? ''))); }
                        if (!$clientName) continue;
                        $phone = sanitize($row['TELEFONO'] ?? $row['TELEFONO '] ?? '');
                        $vin = sanitize($row['VIN'] ?? $row['VIN '] ?? '');
                        $serviceType = sanitize($row['TIPO DE TRAMITE'] ?? '');
                        $deliveryDate = $row['FECHA ENTREGA'] ?? null;
                        $deposit = (float)($row['ABONO'] ?? $row['ABONO '] ?? 0);
                        $owed = (float)($row['DEBE'] ?? $row['DEBE '] ?? 0);
                        $total = (float)($row['TOTAL'] ?? 0);
                        $pdo->prepare('INSERT INTO plates (store_id, client_name, phone, vin, service_type, delivery_date, payment, balance, total, status) VALUES (?,?,?,?,?,?,?,?,?,?)')
                            ->execute([$storeId, $clientName, $phone, $vin, $serviceType, $deliveryDate, $deposit, $owed, $total, 'pending']);
                        $rowsImported++;
                    }
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }

        $importStatus = ($rowsImported === 0 && $errors) ? 'failed' : 'completed';
        $sheetMapping = is_array($data['sheet_mapping'] ?? null) ? $data['sheet_mapping'] : [];
        if ($importSummary) {
            $sheetMapping['_summary'] = $importSummary;
        }
        $pdo->prepare('UPDATE excel_imports SET status = ?, rows_imported = ?, sheet_mapping = ?, errors = ?, original_name = COALESCE(NULLIF(original_name, \'\'), ?) WHERE id = ? AND store_id = ?')
            ->execute([
                $importStatus,
                $rowsImported,
                json_encode($sheetMapping),
                implode("\n", $errors),
                (string)($impRow['original_name'] ?? ''),
                $importId,
                $storeId,
            ]);
        if ($importStatus === 'failed') {
            json_error(implode("\n", $errors), 422);
        }

        $reconVariances = [];
        if ($importedCaja) {
            try {
                $reconVariances = recon_after_caja_import($pdo, $storeId, $importId);
            } catch (Throwable $e) {
                error_log('[import] reconciliation after caja: ' . $e->getMessage());
            }
        }

        json_response([
            'success'        => true,
            'rows_imported'  => $rowsImported,
            'errors'         => $errors,
            'variances'      => $reconVariances,
            'variance_count' => count($reconVariances),
            'summary'        => $importSummary,
            'report_id'      => (int)($importSummary['report_id'] ?? 0),
        ]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
