<?php
/**
 * Admin price comparison — invoice parse → supplier unit costs → cheapest provider.
 */
$user = auth_require_admin();
$method = get_method();
$pdo = db();
require_once __DIR__ . '/../includes/gemini.php';
require_once __DIR__ . '/../includes/price-comparison.php';

if (!price_comparison_tables_exist($pdo)) {
    json_error('Price comparison is not set up. Apply migration 020_supplier_prices.sql.', 503);
}

function pc_first_upload(): ?array {
    foreach (['file', 'image', 'invoice', 'photo', 'receipt'] as $key) {
        if (!empty($_FILES[$key]) && is_array($_FILES[$key]) && !is_array($_FILES[$key]['name'] ?? null)) {
            return $_FILES[$key];
        }
    }
    return null;
}

if ($method === 'GET') {
    $action = (string)($_GET['action'] ?? 'recommendations');
    $storeId = resolve_store_filter(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);

    if ($action === 'list_suppliers') {
        $sql = 'SELECT id, store_id, name, active FROM suppliers WHERE '
            . sql_is_true('active');
        $params = [];
        if ($storeId) {
            $sql .= ' AND (store_id IS NULL OR store_id = ?)';
            $params[] = $storeId;
        }
        $sql .= ' ORDER BY name LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        json_response(['suppliers' => $stmt->fetchAll() ?: []]);
    }

    if ($action === 'inventory_options') {
        $sid = $storeId ?: resolve_store_id(null);
        $stmt = $pdo->prepare(
            'SELECT id, product_name, barcode FROM inventory WHERE store_id = ? ORDER BY product_name LIMIT 2000'
        );
        $stmt->execute([$sid]);
        json_response(['products' => $stmt->fetchAll() ?: []]);
    }

    if ($action === 'recommendations') {
        json_response([
            'recommendations' => price_comparison_recommendations($pdo, $storeId),
        ]);
    }

    json_error('Unknown action', 400);
}

if ($method === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? null;
    $data = [];
    if ($action === null) {
        $data = get_json_body();
        $action = (string)($data['action'] ?? '');
    }

    if ($action === 'upsert_supplier') {
        if ($data === []) {
            $data = get_json_body();
        }
        $name = sanitize((string)($data['name'] ?? ''));
        if ($name === '') {
            json_error('Supplier name is required.', 422);
        }
        $storeId = !empty($data['store_id']) ? (int)$data['store_id'] : resolve_store_id(null);
        $id = (int)($data['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE suppliers SET name = ?, store_id = ? WHERE id = ?')
                ->execute([$name, $storeId, $id]);
            json_response(['success' => true, 'id' => $id, 'name' => $name]);
        }
        try {
            $pdo->prepare('INSERT INTO suppliers (store_id, name) VALUES (?, ?)')
                ->execute([$storeId, $name]);
        } catch (Throwable $e) {
            // Unique name — return existing
            $stmt = $pdo->prepare(
                'SELECT id, name FROM suppliers WHERE COALESCE(store_id, 0) = ? AND LOWER(name) = LOWER(?) LIMIT 1'
            );
            $stmt->execute([$storeId ?: 0, $name]);
            $existing = $stmt->fetch();
            if ($existing) {
                json_response(['success' => true, 'id' => (int)$existing['id'], 'name' => (string)$existing['name']]);
            }
            json_error('Could not create supplier.', 500);
        }
        json_response([
            'success' => true,
            'id' => sql_last_insert_id($pdo, 'suppliers'),
            'name' => $name,
        ], 201);
    }

    if ($action === 'parse_invoice') {
        if (!gemini_configured()) {
            json_error('AI parser is not configured. Set GEMINI_API_KEY.', 503);
        }
        $file = pc_first_upload();
        if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            json_error('An invoice image or PDF is required.', 422);
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_readable($tmp)) {
            json_error('Upload unreadable. Try again.', 422);
        }
        $filename = basename((string)($file['name'] ?? 'invoice.jpg')) ?: 'invoice.jpg';
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = $finfo ? finfo_file($finfo, $tmp) : false;
        if ($finfo) {
            finfo_close($finfo);
        }
        $mime = is_string($detected) ? strtolower($detected) : strtolower((string)($file['type'] ?? ''));
        if (in_array($mime, ['image/jpg', 'image/pjpeg'], true)) {
            $mime = 'image/jpeg';
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
            $byName = gemini_mime_for_filename($filename);
            if (in_array($byName, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
                $mime = $byName;
            } else {
                json_error('Use JPG, PNG, WEBP, or PDF.', 422);
            }
        }
        $path = upload_file($file, 'supplier-invoices', false);
        if (!$path) {
            json_error('Could not store invoice file.', 500);
        }
        $localPath = $tmp;
        // If stored remotely, parse from original tmp before it goes away
        try {
            $parsed = gemini_parse_receipt($localPath, $mime, $filename);
        } catch (Throwable $e) {
            error_log('[price-comparison] parse: ' . $e->getMessage());
            json_error('Could not read this invoice. Try a clearer photo or PDF.', 502);
        }
        $storeId = !empty($_POST['store_id']) ? (int)$_POST['store_id'] : resolve_store_id(null);
        $lines = price_comparison_enrich_lines($pdo, $storeId, $parsed['line_items'] ?? []);
        json_response([
            'success' => true,
            'vendor' => $parsed['vendor'] ?? '',
            'date' => $parsed['date'] ?? '',
            'total' => $parsed['total'] ?? 0,
            'source_path' => $path,
            'source_url' => stored_file_url($path),
            'line_items' => $lines,
            'suggested_supplier' => $parsed['vendor'] ?? '',
        ]);
    }

    if ($action === 'save_prices') {
        if ($data === []) {
            $data = get_json_body();
        }
        $storeId = !empty($data['store_id']) ? (int)$data['store_id'] : resolve_store_id(null);
        $supplierId = (int)($data['supplier_id'] ?? 0);
        $supplierName = sanitize((string)($data['supplier_name'] ?? ''));
        if ($supplierId <= 0) {
            if ($supplierName === '') {
                json_error('Select or create a supplier.', 422);
            }
            try {
                $pdo->prepare('INSERT INTO suppliers (store_id, name) VALUES (?, ?)')
                    ->execute([$storeId, $supplierName]);
                $supplierId = sql_last_insert_id($pdo, 'suppliers');
            } catch (Throwable $e) {
                $stmt = $pdo->prepare(
                    'SELECT id FROM suppliers WHERE COALESCE(store_id, 0) = ? AND LOWER(name) = LOWER(?) LIMIT 1'
                );
                $stmt->execute([$storeId ?: 0, $supplierName]);
                $supplierId = (int)($stmt->fetchColumn() ?: 0);
                if ($supplierId <= 0) {
                    json_error('Could not resolve supplier.', 500);
                }
            }
        }
        $sourcePath = sanitize((string)($data['source_path'] ?? ''));
        $invoiceDate = (string)($data['invoice_date'] ?? '');
        if ($invoiceDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
            $invoiceDate = '';
        }
        $lines = $data['lines'] ?? [];
        if (!is_array($lines) || $lines === []) {
            json_error('No invoice lines to save.', 422);
        }
        $ins = $pdo->prepare(
            'INSERT INTO supplier_prices
             (supplier_id, inventory_id, barcode, product_name, unit_cost, quantity, invoice_date, source_path, created_by)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $saved = 0;
        foreach ($lines as $line) {
            if (!is_array($line) || !empty($line['skip'])) {
                continue;
            }
            $invId = (int)($line['inventory_id'] ?? 0);
            if ($invId <= 0) {
                continue;
            }
            $unit = round((float)($line['unit_cost'] ?? 0), 2);
            if ($unit <= 0) {
                continue;
            }
            $qty = max(0.001, (float)($line['qty'] ?? 1));
            $name = sanitize((string)($line['description'] ?? $line['product_name'] ?? ''));
            $barcode = price_comparison_normalize_barcode((string)($line['barcode'] ?? ''));
            $ins->execute([
                $supplierId,
                $invId,
                $barcode !== '' ? $barcode : null,
                $name,
                $unit,
                $qty,
                $invoiceDate !== '' ? $invoiceDate : null,
                $sourcePath !== '' ? $sourcePath : null,
                (int)$user['id'],
            ]);
            $saved++;
        }
        if ($saved === 0) {
            json_error('Link at least one line to a product with a unit cost.', 422);
        }
        json_response([
            'success' => true,
            'saved' => $saved,
            'supplier_id' => $supplierId,
            'recommendations' => price_comparison_recommendations($pdo, $storeId),
        ]);
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
