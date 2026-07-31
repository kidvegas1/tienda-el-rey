<?php
/**
 * Receipts — upload, AI parse, editable business expenses.
 */
$user = auth_require();
$method = get_method();
$pdo = db();
require_once __DIR__ . '/../includes/gemini.php';

if ($method === 'GET') {
    $storeId = resolve_store_filter(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
    $id = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($id > 0) {
        $stmt = $pdo->prepare(
            'SELECT r.*, s.name AS store_name FROM receipts r
             LEFT JOIN stores s ON s.id = r.store_id
             WHERE r.id = ?' . store_filter_sql('r.store_id', $storeId)
        );
        $stmt->execute($storeId ? [$id, $storeId] : [$id]);
        $receipt = $stmt->fetch();
        if (!$receipt) json_error('Receipt not found', 404);
        $items = $pdo->prepare('SELECT * FROM receipt_items WHERE receipt_id = ? ORDER BY sort_order, id');
        $items->execute([$id]);
        $receipt['items'] = $items->fetchAll();
        $receipt['image_url'] = !empty($receipt['image_path']) ? stored_file_url($receipt['image_path']) : null;
        json_response(['receipt' => $receipt]);
    }

    $status = $_GET['status'] ?? 'all';
    $sql = 'SELECT r.*, s.name AS store_name FROM receipts r
            LEFT JOIN stores s ON s.id = r.store_id WHERE 1=1'
        . store_filter_sql('r.store_id', $storeId);
    $params = $storeId ? [$storeId] : [];
    if ($status !== 'all') {
        $sql .= ' AND r.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY COALESCE(r.receipt_date, DATE(r.created_at)) DESC, r.id DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    $itemsByReceipt = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $itemStmt = $pdo->prepare(
            "SELECT * FROM receipt_items WHERE receipt_id IN ($ph) ORDER BY sort_order, id"
        );
        $itemStmt->execute($ids);
        foreach ($itemStmt->fetchAll() ?: [] as $it) {
            $rid = (int)$it['receipt_id'];
            $itemsByReceipt[$rid][] = $it;
        }
    }
    foreach ($rows as &$row) {
        $row['image_url'] = !empty($row['image_path']) ? stored_file_url($row['image_path']) : null;
        $row['items'] = $itemsByReceipt[(int)$row['id']] ?? [];
    }
    unset($row);

    $sumSql = 'SELECT COALESCE(SUM(total),0) AS expenses, COALESCE(SUM(tax),0) AS tax_paid
               FROM receipts WHERE status = ?' . store_filter_sql('store_id', $storeId);
    $sumStmt = $pdo->prepare($sumSql);
    $sumStmt->execute(array_merge(['approved'], $storeId ? [$storeId] : []));
    $sums = $sumStmt->fetch() ?: [];

    json_response([
        'receipts'  => $rows,
        'expenses'  => (float)($sums['expenses'] ?? 0),
        'tax_paid'  => (float)($sums['tax_paid'] ?? 0),
    ]);
}

if ($method === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? null;
    $data = [];
    if ($action === null) {
        $data = get_json_body();
        $action = $data['action'] ?? '';
    }

    if ($action === 'upload_parse') {
        if (!gemini_configured()) {
            json_error('AI receipt parser is not configured. Set GEMINI_API_KEY in server environment.', 503);
        }
        // Prefer first usable upload key (mobile clients vary)
        $file = null;
        foreach (['file', 'image', 'receipt', 'photo'] as $key) {
            if (!empty($_FILES[$key]) && is_array($_FILES[$key]) && !is_array($_FILES[$key]['name'] ?? null)) {
                $file = $_FILES[$key];
                break;
            }
        }
        if (!$file && empty($_FILES) && empty($_POST)) {
            json_error('Upload too large for the server. Use a smaller photo or JPEG under 8MB.', 413);
        }
        if (!$file) {
            json_error('A receipt image or PDF is required. Take or choose a photo again.', 422);
        }
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            $msg = match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Photo is too large. Retake closer / lower quality (under 8MB).',
                UPLOAD_ERR_PARTIAL => 'Upload interrupted. Try again.',
                UPLOAD_ERR_NO_FILE => 'No image received. Take or choose a photo again.',
                default => 'A receipt image or PDF is required.',
            };
            json_error($msg, 422);
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_readable($tmp)) {
            json_error('No image received. Take or choose a photo again.', 422);
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            $size = (int)@filesize($tmp);
        }
        if ($size <= 0) {
            json_error('The image file is empty. Take the photo again.', 422);
        }

        $filename = basename((string)($file['name'] ?? 'receipt.jpg')) ?: 'receipt.jpg';
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = $finfo ? finfo_file($finfo, $tmp) : false;
        if ($finfo) finfo_close($finfo);
        $mime = is_string($detected) ? strtolower($detected) : strtolower((string)($file['type'] ?? ''));
        if (in_array($mime, ['image/jpg', 'image/pjpeg'], true)) {
            $mime = 'image/jpeg';
        }
        if (in_array($mime, ['image/x-png'], true)) {
            $mime = 'image/png';
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
            $byName = gemini_mime_for_filename($filename);
            if (in_array($byName, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
                $mime = $byName;
            } else {
                // Magic bytes — common when mobile sends empty / octet-stream MIME
                $head = @file_get_contents($tmp, false, null, 0, 8);
                if (is_string($head) && str_starts_with($head, "\xFF\xD8\xFF")) {
                    $mime = 'image/jpeg';
                } elseif (is_string($head) && str_starts_with($head, "\x89PNG")) {
                    $mime = 'image/png';
                } elseif (is_string($head) && str_starts_with($head, '%PDF')) {
                    $mime = 'application/pdf';
                } else {
                    $info = @getimagesize($tmp);
                    $mime = is_array($info) && !empty($info['mime']) ? (string)$info['mime'] : '';
                    if ($mime === 'image/jpg') $mime = 'image/jpeg';
                }
            }
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
            json_error('Unsupported file type. Use JPG, PNG, WEBP, or PDF (not HEIC/Live Photo).', 400);
        }

        // Recompress oversized phone photos so parse + store succeed
        if ($mime !== 'application/pdf' && ($size > MAX_UPLOAD_SIZE || $size > 2_500_000) && function_exists('imagecreatefromstring')) {
            $raw = @file_get_contents($tmp);
            $src = $raw !== false ? @imagecreatefromstring($raw) : false;
            if ($src !== false) {
                $w = imagesx($src); $h = imagesy($src);
                $max = 1800;
                $scale = min(1.0, $max / max($w, $h));
                $nw = max(1, (int)round($w * $scale));
                $nh = max(1, (int)round($h * $scale));
                $dst = imagecreatetruecolor($nw, $nh);
                if ($dst) {
                    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                    $out = tempnam(sys_get_temp_dir(), 'rcpt');
                    if ($out && imagejpeg($dst, $out, 85)) {
                        @copy($out, $tmp);
                        @unlink($out);
                        $mime = 'image/jpeg';
                        $filename = preg_replace('/\.[^.]+$/', '', $filename) . '.jpg';
                        $file['name'] = $filename;
                        $file['type'] = $mime;
                        $file['size'] = (int)@filesize($tmp);
                        $size = $file['size'];
                    }
                    imagedestroy($dst);
                }
                imagedestroy($src);
            }
        }
        if ($size > MAX_UPLOAD_SIZE) {
            json_error('File exceeds maximum upload size. Try a smaller JPEG.', 400);
        }

        try {
            $parsed = gemini_parse_receipt($tmp, $mime, $filename);
        } catch (Throwable $e) {
            error_log('receipts parse: ' . $e->getMessage());
            json_error('Receipt parsing failed. Try a clearer, well-lit photo of the whole receipt.', 502);
        }

        $file['tmp_name'] = $tmp;
        $file['type'] = $mime;
        $file['name'] = $filename;
        $file['size'] = $size > 0 ? $size : (int)@filesize($tmp);
        $stored = upload_file($file, 'receipts');
        if ($stored === false) {
            // Fallback: copy manually if is_uploaded_file quirks after recompress
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: 'jpg');
            $name = uniqid('receipt_', true) . '.' . $ext;
            $destDir = rtrim(UPLOAD_DIR, '/') . '/receipts';
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0755, true);
            }
            $dest = $destDir . '/' . $name;
            if (!@copy($tmp, $dest)) {
                json_error('Failed to store receipt image.', 500);
            }
            $stored = 'assets/uploads/receipts/' . $name;
        }

        $storeId = resolve_store_id(!empty($_POST['store_id']) ? (int)$_POST['store_id'] : null);
        $subtotal = (float)($parsed['subtotal'] ?? 0);
        $tax = (float)($parsed['tax'] ?? 0);
        $total = (float)($parsed['total'] ?? 0);
        if ($total <= 0 && $subtotal > 0) {
            $total = round($subtotal + $tax, 2);
        }

        $pdo->prepare(
            'INSERT INTO receipts
             (store_id, user_id, image_path, vendor, receipt_date, subtotal, tax, total, category, notes, status, ai_raw_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $storeId,
            (int)$user['id'],
            $stored,
            $parsed['vendor'] ?: null,
            $parsed['date'] !== '' ? $parsed['date'] : date('Y-m-d'),
            $subtotal,
            $tax,
            $total,
            $parsed['category_suggestion'] ?: 'Other',
            null,
            'pending',
            json_encode($parsed, JSON_UNESCAPED_UNICODE),
        ]);
        $receiptId = (int)sql_last_insert_id($pdo, 'receipts');

        $ins = $pdo->prepare(
            'INSERT INTO receipt_items (receipt_id, description, quantity, amount, sort_order) VALUES (?,?,?,?,?)'
        );
        $i = 0;
        foreach ($parsed['line_items'] as $item) {
            $ins->execute([
                $receiptId,
                $item['description'] !== '' ? $item['description'] : 'Item',
                $item['qty'],
                $item['amount'],
                $i++,
            ]);
        }

        json_response([
            'success'    => true,
            'id'         => $receiptId,
            'receipt'    => $parsed,
            'image_path' => $stored,
            'image_url'  => stored_file_url($stored),
        ], 201);
    }

    if ($action === 'update') {
        validate_required($data, ['id']);
        $storeId = resolve_store_id(!empty($data['store_id']) ? (int)$data['store_id'] : null);
        $id = (int)$data['id'];
        $check = $pdo->prepare('SELECT id, status FROM receipts WHERE id = ? AND store_id = ?');
        $check->execute([$id, $storeId]);
        if (!$check->fetch()) json_error('Receipt not found', 404);

        $pdo->prepare(
            'UPDATE receipts SET vendor=?, receipt_date=?, subtotal=?, tax=?, total=?, category=?, notes=?, status=?
             WHERE id=? AND store_id=?'
        )->execute([
            sanitize($data['vendor'] ?? ''),
            $data['receipt_date'] ?? date('Y-m-d'),
            (float)($data['subtotal'] ?? 0),
            (float)($data['tax'] ?? 0),
            (float)($data['total'] ?? 0),
            sanitize($data['category'] ?? 'Other'),
            sanitize($data['notes'] ?? ''),
            in_array($data['status'] ?? '', ['pending', 'approved', 'rejected'], true) ? $data['status'] : 'pending',
            $id,
            $storeId,
        ]);

        if (isset($data['items']) && is_array($data['items'])) {
            $pdo->prepare('DELETE FROM receipt_items WHERE receipt_id = ?')->execute([$id]);
            $ins = $pdo->prepare(
                'INSERT INTO receipt_items (receipt_id, description, quantity, amount, sort_order) VALUES (?,?,?,?,?)'
            );
            $i = 0;
            foreach ($data['items'] as $item) {
                if (!is_array($item)) continue;
                $amt = (float)($item['amount'] ?? 0);
                $desc = sanitize($item['description'] ?? '');
                if ($desc === '' && $amt <= 0) continue;
                $ins->execute([$id, $desc !== '' ? $desc : 'Item', max(0.001, (float)($item['qty'] ?? 1)), $amt, $i++]);
            }
        }
        json_response(['success' => true]);
    }

    if ($action === 'approve') {
        validate_required($data, ['id']);
        $storeId = resolve_store_id(!empty($data['store_id']) ? (int)$data['store_id'] : null);
        $id = (int)$data['id'];
        $stmt = $pdo->prepare('SELECT * FROM receipts WHERE id = ? AND store_id = ?');
        $stmt->execute([$id, $storeId]);
        $receipt = $stmt->fetch();
        if (!$receipt) json_error('Receipt not found', 404);

        $pdo->beginTransaction();
        try {
            $entryId = (int)($receipt['accounting_entry_id'] ?? 0);
            if ($entryId <= 0) {
                $pdo->prepare(
                    'INSERT INTO accounting_entries (store_id, category, description, amount, entry_type, entry_date, notes)
                     VALUES (?,?,?,?,?,?,?)'
                )->execute([
                    $storeId,
                    $receipt['category'] ?: 'Other',
                    trim(($receipt['vendor'] ?: 'Expense') . ' — receipt #' . $id),
                    (float)$receipt['total'],
                    'payable',
                    $receipt['receipt_date'] ?: date('Y-m-d'),
                    'Tax $' . number_format((float)$receipt['tax'], 2) . ' · from receipt upload',
                ]);
                $entryId = (int)sql_last_insert_id($pdo, 'accounting_entries');
            }
            $pdo->prepare('UPDATE receipts SET status=?, accounting_entry_id=? WHERE id=? AND store_id=?')
                ->execute(['approved', $entryId, $id, $storeId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        json_response(['success' => true, 'accounting_entry_id' => $entryId]);
    }

    if ($action === 'delete') {
        validate_required($data, ['id']);
        $storeId = resolve_store_id(!empty($data['store_id']) ? (int)$data['store_id'] : null);
        $pdo->prepare('DELETE FROM receipts WHERE id = ? AND store_id = ?')->execute([(int)$data['id'], $storeId]);
        json_response(['success' => true]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
