<?php
/**
 * Finances — Reality Check KPIs + CPA tax export.
 */
$user = auth_require();
$method = get_method();
$pdo = db();
require_once __DIR__ . '/../includes/reconciliation.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/commission.php';

if ($method !== 'GET') {
    json_error('Method not allowed', 405);
}

$period = $_GET['period'] ?? 'monthly';
$action = $_GET['action'] ?? 'summary';
$storeId = resolve_store_filter(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);

$dateFrom = $_GET['date_from'] ?? null;
$dateTo = $_GET['date_to'] ?? null;
if (!$dateFrom || !$dateTo) {
    $now = new DateTime('now');
    switch ($period) {
        case 'daily':
            $dateFrom = $now->format('Y-m-d');
            $dateTo = $dateFrom;
            break;
        case 'weekly':
            $dateFrom = (clone $now)->modify('monday this week')->format('Y-m-d');
            $dateTo = $now->format('Y-m-d');
            break;
        case 'annual':
            $dateFrom = $now->format('Y-01-01');
            $dateTo = $now->format('Y-12-31');
            break;
        case 'all':
            $dateFrom = '2000-01-01';
            $dateTo = '2099-12-31';
            break;
        case 'monthly':
        default:
            $dateFrom = $now->format('Y-m-01');
            $dateTo = $now->format('Y-m-t');
    }
}

$dateFromDt = $dateFrom . ' 00:00:00';
$dateToDt = $dateTo . ' 23:59:59';
$storeSql = store_filter_sql('store_id', $storeId);
$storeParams = $storeId ? [$storeId] : [];

// ── Inventory sales (sargable created_at) ──
$invSql = "SELECT
    COUNT(*) AS cnt,
    COALESCE(SUM(im.unit_price * ABS(im.quantity)), 0) AS revenue,
    COALESCE(SUM(im.tax_amount), 0) AS tax,
    COALESCE(SUM(im.total_amount), 0) AS gross,
    COALESCE(SUM(i.cost_price * ABS(im.quantity)), 0) AS cogs
 FROM inventory_movements im
 LEFT JOIN inventory i ON i.id = im.inventory_id
 WHERE im.movement_type = 'sale'
   AND COALESCE(im.status, 'active') = 'active'
   AND im.created_at >= ? AND im.created_at <= ?" . store_filter_sql('im.store_id', $storeId);
$invStmt = $pdo->prepare($invSql);
$invStmt->execute(array_merge([$dateFromDt, $dateToDt], $storeParams));
$inv = $invStmt->fetch() ?: [];
$invRevenue = (float)($inv['revenue'] ?? 0);
$invTax = (float)($inv['tax'] ?? 0);
$invCogs = (float)($inv['cogs'] ?? 0);
$invProfit = round($invRevenue - $invCogs, 2);

// ── Barri txns by stream ──
$barriSql = "SELECT transaction_type, principal, fee, tax, total, ag_commission, transaction_date, reference_number, store_id
 FROM barri_transactions
 WHERE transaction_date BETWEEN ? AND ?" . $storeSql;
$barriStmt = $pdo->prepare($barriSql);
$barriStmt->execute(array_merge([$dateFrom, $dateTo], $storeParams));
$barriRows = $barriStmt->fetchAll();

$giros = ['count' => 0, 'volume' => 0.0, 'fees' => 0.0, 'tax' => 0.0, 'commission' => 0.0];
$cambio = ['count' => 0, 'volume' => 0.0, 'fees' => 0.0, 'tax' => 0.0, 'commission' => 0.0, 'cents_lost' => 0.0, 'tier_commission' => 0.0];

foreach ($barriRows as $row) {
    $type = (string)($row['transaction_type'] ?? '');
    $norm = str_replace(' ', '_', strtolower(trim($type)));
    $principal = abs((float)$row['principal']);
    $isCheckCashing = commission_is_check_cashing_type($type);
    $isGiros = $norm === 'giros' || $norm === 'money_transfer';
    if ($isCheckCashing) {
        $cambio['count']++;
        $cambio['volume'] += $principal;
        $cambio['fees'] += (float)$row['fee'];
        $cambio['tax'] += (float)$row['tax'];
        $cambio['commission'] += (float)$row['ag_commission'];
        $cambio['cents_lost'] += recon_cents_lost((float)$row['total']);
    } elseif ($isGiros) {
        $giros['count']++;
        $giros['volume'] += (float)$row['principal'];
        $giros['fees'] += (float)$row['fee'];
        $giros['tax'] += (float)$row['tax'];
        $giros['commission'] += (float)$row['ag_commission'];
    }
}

if ($giros['count'] === 0) {
    $trSql = "SELECT COUNT(*) AS cnt,
        COALESCE(SUM(amount_usd),0) AS volume,
        COALESCE(SUM(fee),0) AS fees,
        COALESCE(SUM(tax),0) AS tax
     FROM transfers
     WHERE date_sent BETWEEN ? AND ?
       AND REPLACE(LOWER(COALESCE(transaction_type,'')),' ','_') IN ('giros','money_transfer')"
        . $storeSql;
    $trStmt = $pdo->prepare($trSql);
    $trStmt->execute(array_merge([$dateFromDt, $dateToDt], $storeParams));
    $tr = $trStmt->fetch() ?: [];
    $giros['count'] = (int)($tr['cnt'] ?? 0);
    $giros['volume'] = (float)($tr['volume'] ?? 0);
    $giros['fees'] = (float)($tr['fees'] ?? 0);
    $giros['tax'] = (float)($tr['tax'] ?? 0);
}

foreach (['volume', 'fees', 'tax', 'commission'] as $k) {
    $giros[$k] = round($giros[$k], 2);
}
foreach (['volume', 'fees', 'tax', 'commission', 'cents_lost'] as $k) {
    $cambio[$k] = round($cambio[$k], 2);
}

$checkCommission = commission_tracker_payload($pdo, $storeId, $dateFrom, $dateTo);
$cambio['tier_commission'] = round((float)($checkCommission['commission'] ?? 0), 2);
$cambio['effective_rate_pct'] = round((float)($checkCommission['current_rate_pct'] ?? 0), 2);
$cambio['check_count'] = (int)($checkCommission['check_count'] ?? $cambio['count']);

$girosProfit = round($giros['commission'] > 0 ? $giros['commission'] : $giros['fees'], 2);
$cambioProfit = round($cambio['tier_commission'] + $cambio['fees'] - $cambio['cents_lost'], 2);

// ── Receipt expenses + tax paid on purchases ──
$receiptSql = "SELECT
    COALESCE(SUM(CASE WHEN status='approved' THEN total ELSE 0 END),0) AS expenses,
    COALESCE(SUM(CASE WHEN status='approved' THEN tax ELSE 0 END),0) AS receipt_tax,
    COALESCE(SUM(CASE WHEN status='pending' THEN total ELSE 0 END),0) AS pending_expenses
 FROM receipts
 WHERE COALESCE(receipt_date, DATE(created_at)) BETWEEN ? AND ?" . $storeSql;
$receiptStmt = $pdo->prepare($receiptSql);
$receiptStmt->execute(array_merge([$dateFrom, $dateTo], $storeParams));
$receipts = $receiptStmt->fetch() ?: [];
$expenses = (float)($receipts['expenses'] ?? 0);
$receiptTaxPaid = (float)($receipts['receipt_tax'] ?? 0);
$pendingExpenses = (float)($receipts['pending_expenses'] ?? 0);

// Tax payables in accounting (remittances / tax bills)
$taxPaySql = "SELECT COALESCE(SUM(amount),0) AS tax_paid
 FROM accounting_entries
 WHERE entry_type='payable'
   AND entry_date BETWEEN ? AND ?
   AND (LOWER(category) LIKE '%tax%' OR LOWER(category) LIKE '%impuesto%')"
    . $storeSql;
$taxPayStmt = $pdo->prepare($taxPaySql);
$taxPayStmt->execute(array_merge([$dateFrom, $dateTo], $storeParams));
$acctTaxPaid = (float)(($taxPayStmt->fetch() ?: [])['tax_paid'] ?? 0);

$taxesPaid = round($receiptTaxPaid + $acctTaxPaid, 2);
$totalProfit = round($invProfit + $girosProfit + $cambioProfit - $expenses, 2);
$operatingIncome = round($invRevenue + $girosProfit + $cambioProfit, 2);
$totalRevenue = $operatingIncome;
$taxCollected = round($invTax + $giros['tax'] + $cambio['tax'], 2);

$ledgerSql = "SELECT
    COALESCE(SUM(owed_to_store),0) AS owed_to_store,
    COALESCE(SUM(store_owes),0) AS store_owes
 FROM internal_ledger WHERE status = 'open'" . $storeSql;
$ledgerStmt = $pdo->prepare($ledgerSql);
$ledgerStmt->execute($storeParams);
$ledger = $ledgerStmt->fetch() ?: [];
$owedToStore = (float)($ledger['owed_to_store'] ?? 0);
$storeOwes = (float)($ledger['store_owes'] ?? 0);

$acctSql = "SELECT
    COALESCE(SUM(CASE WHEN entry_type='receivable' THEN amount ELSE 0 END),0) AS receivable,
    COALESCE(SUM(CASE WHEN entry_type='payable' THEN amount ELSE 0 END),0) AS payable
 FROM accounting_entries WHERE entry_date BETWEEN ? AND ?" . $storeSql;
$acctStmt = $pdo->prepare($acctSql);
$acctStmt->execute(array_merge([$dateFrom, $dateTo], $storeParams));
$acct = $acctStmt->fetch() ?: [];

$varSql = "SELECT COALESCE(SUM(ABS(diff_amount)),0) AS variance_open
 FROM reconciliation_variances WHERE status = 'open'" . $storeSql;
$varStmt = $pdo->prepare($varSql);
$varStmt->execute($storeParams);
$varianceOpen = (float)(($varStmt->fetch() ?: [])['variance_open'] ?? 0);

$debtDenom = $owedToStore + $storeOwes;
$debtRatio = $debtDenom > 0 ? round($storeOwes / $debtDenom, 4) : 0.0;
$envioVolume = $giros['volume'] > 0 ? $giros['volume'] : 1.0;
$feeRatePct = round(($giros['fees'] / $envioVolume) * 100, 2);
$verdict = $totalProfit > 0.009 ? 'profit' : ($totalProfit < -0.009 ? 'loss' : 'break');

$storeName = 'All Stores';
if ($storeId) {
    $sn = $pdo->prepare('SELECT name FROM stores WHERE id = ?');
    $sn->execute([$storeId]);
    $storeName = (string)($sn->fetchColumn() ?: ('Store #' . $storeId));
}

$payload = [
    'scope'     => $storeId ? 'store' : 'all',
    'store_id'  => $storeId,
    'store_name'=> $storeName,
    'date_from' => $dateFrom,
    'date_to'   => $dateTo,
    'period'    => $period,
    'verdict'   => $verdict,
    'tax_label' => inventory_tax_label(),
    'tax_rate_pct' => inventory_global_tax_rate(),
    'streams'   => [
        'inventory' => [
            'count'   => (int)($inv['cnt'] ?? 0),
            'revenue' => round($invRevenue, 2),
            'gross'   => round((float)($inv['gross'] ?? 0), 2),
            'tax'     => round($invTax, 2),
            'cogs'    => round($invCogs, 2),
            'profit'  => $invProfit,
        ],
        'giros' => [
            'count'      => $giros['count'],
            'volume'     => $giros['volume'],
            'fees'       => $giros['fees'],
            'tax'        => $giros['tax'],
            'commission' => $giros['commission'],
            'profit'     => $girosProfit,
        ],
        'cambio' => [
            'count'      => $cambio['check_count'] ?: $cambio['count'],
            'volume'     => round($checkCommission['volume'] ?? $cambio['volume'], 2),
            'fees'       => $cambio['fees'],
            'tax'        => $cambio['tax'],
            'commission' => $cambio['commission'],
            'tier_commission' => $cambio['tier_commission'],
            'effective_rate_pct' => $cambio['effective_rate_pct'],
            'cents_lost' => $cambio['cents_lost'],
            'profit'     => $cambioProfit,
        ],
        'expenses' => [
            'approved' => round($expenses, 2),
            'pending'  => round($pendingExpenses, 2),
            'tax_paid' => round($receiptTaxPaid, 2),
        ],
    ],
    'totals' => [
        'revenue'       => $totalRevenue,
        'operating_income' => $operatingIncome,
        'profit'        => $totalProfit,
        'loss'          => $totalProfit < 0 ? abs($totalProfit) : 0.0,
        'expenses'      => round($expenses, 2),
        'tax_collected' => $taxCollected,
        'taxes_paid'    => $taxesPaid,
        'tax_breakdown' => [
            'inventory' => round($invTax, 2),
            'giros'     => $giros['tax'],
            'cambio'    => $cambio['tax'],
        ],
        'taxes_paid_breakdown' => [
            'receipts'   => round($receiptTaxPaid, 2),
            'accounting' => round($acctTaxPaid, 2),
        ],
    ],
    'debt' => [
        'ledger_owed_to_store'  => round($owedToStore, 2),
        'ledger_store_owes'     => round($storeOwes, 2),
        'accounting_receivable' => round((float)($acct['receivable'] ?? 0), 2),
        'accounting_payable'    => round((float)($acct['payable'] ?? 0), 2),
        'variance_open'         => round($varianceOpen, 2),
        'debt_ratio'            => $debtRatio,
    ],
    'kpis' => [
        'fee_rate_pct'     => $feeRatePct,
        'cambio_cents_pct' => $cambio['volume'] > 0 ? round(($cambio['cents_lost'] / $cambio['volume']) * 100, 3) : 0.0,
        'debt_ratio'       => $debtRatio,
        'inventory_margin' => $invRevenue > 0 ? round(($invProfit / $invRevenue) * 100, 2) : 0.0,
        'net_tax_liability'=> round($taxCollected - $taxesPaid, 2),
    ],
];

if ($action === 'tax_export') {
    // Inventory detail lines
    $detailSql = "SELECT DATE(im.created_at) AS sale_date, COALESCE(i.product_name,'(product)') AS product,
        ABS(im.quantity) AS qty, im.unit_price, im.tax_rate, im.tax_amount, im.total_amount
     FROM inventory_movements im
     LEFT JOIN inventory i ON i.id = im.inventory_id
     WHERE im.movement_type='sale'
       AND COALESCE(im.status, 'active') = 'active'
       AND im.created_at >= ? AND im.created_at <= ?"
        . store_filter_sql('im.store_id', $storeId)
        . ' ORDER BY im.created_at';
    $detailStmt = $pdo->prepare($detailSql);
    $detailStmt->execute(array_merge([$dateFromDt, $dateToDt], $storeParams));
    $invLines = $detailStmt->fetchAll();

    $rcptSql = "SELECT receipt_date, vendor, category, subtotal, tax, total, status
     FROM receipts
     WHERE status = 'approved'
       AND COALESCE(receipt_date, DATE(created_at)) BETWEEN ? AND ?" . $storeSql
        . ' ORDER BY receipt_date, id';
    $rcptStmt = $pdo->prepare($rcptSql);
    $rcptStmt->execute(array_merge([$dateFrom, $dateTo], $storeParams));
    $rcptLines = $rcptStmt->fetchAll();

    $filename = 'tax-cpa-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($storeName))
        . "-{$dateFrom}-to-{$dateTo}.csv";

    if (ob_get_length()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

    fputcsv($out, ['TAX CPA PACKAGE — Tienda Hispana El Rey']);
    fputcsv($out, ['Store', $storeName]);
    fputcsv($out, ['Period From', $dateFrom]);
    fputcsv($out, ['Period To', $dateTo]);
    fputcsv($out, ['Tax Label', inventory_tax_label()]);
    fputcsv($out, ['Default Rate %', inventory_global_tax_rate()]);
    fputcsv($out, []);
    fputcsv($out, ['SUMMARY']);
    fputcsv($out, ['Metric', 'Amount']);
    fputcsv($out, ['Tax collected — inventory', $payload['totals']['tax_breakdown']['inventory']]);
    fputcsv($out, ['Tax collected — giros', $payload['totals']['tax_breakdown']['giros']]);
    fputcsv($out, ['Tax collected — cambio', $payload['totals']['tax_breakdown']['cambio']]);
    fputcsv($out, ['Tax collected — TOTAL', $payload['totals']['tax_collected']]);
    fputcsv($out, ['Check-cashing tier commission', $payload['streams']['cambio']['tier_commission']]);
    fputcsv($out, ['Check-cashing effective rate %', $payload['streams']['cambio']['effective_rate_pct']]);
    fputcsv($out, ['Taxes paid — purchase receipts', $payload['totals']['taxes_paid_breakdown']['receipts']]);
    fputcsv($out, ['Taxes paid — accounting (tax category)', $payload['totals']['taxes_paid_breakdown']['accounting']]);
    fputcsv($out, ['Taxes paid — TOTAL', $payload['totals']['taxes_paid']]);
    fputcsv($out, ['Net tax liability (collected − paid)', $payload['kpis']['net_tax_liability']]);
    fputcsv($out, ['Approved business expenses', $payload['totals']['expenses']]);
    fputcsv($out, ['Net profit (after expenses)', $payload['totals']['profit']]);
    fputcsv($out, []);
    fputcsv($out, ['INVENTORY SALES DETAIL']);
    fputcsv($out, ['Date', 'Product', 'Qty', 'Unit Price', 'Tax Rate %', 'Tax Amount', 'Total']);
    foreach ($invLines as $line) {
        fputcsv($out, [
            $line['sale_date'],
            $line['product'],
            $line['qty'],
            $line['unit_price'],
            $line['tax_rate'],
            $line['tax_amount'],
            $line['total_amount'],
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['REMITTANCE TAX DETAIL (barri)']);
    fputcsv($out, ['Date', 'Stream', 'Type', 'Reference', 'Principal', 'Fee', 'AgCommission', 'Tax', 'Total']);
    foreach ($barriRows as $row) {
        $type = (string)($row['transaction_type'] ?? '');
        $norm = str_replace(' ', '_', strtolower(trim($type)));
        $stream = commission_is_check_cashing_type($type) ? 'cambio' : (($norm === 'giros' || $norm === 'money_transfer') ? 'giros' : 'other');
        if ($stream === 'other') continue;
        fputcsv($out, [
            $row['transaction_date'],
            $stream,
            $type,
            $row['reference_number'] ?? '',
            $row['principal'],
            $row['fee'],
            $row['ag_commission'] ?? 0,
            $row['tax'],
            $row['total'],
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['RECEIPT EXPENSES']);
    fputcsv($out, ['Date', 'Vendor', 'Category', 'Subtotal', 'Tax', 'Total', 'Status']);
    foreach ($rcptLines as $line) {
        fputcsv($out, [
            $line['receipt_date'],
            $line['vendor'],
            $line['category'],
            $line['subtotal'],
            $line['tax'],
            $line['total'],
            $line['status'],
        ]);
    }
    fclose($out);
    exit;
}

json_response($payload);
