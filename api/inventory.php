<?php
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/gemini.php';
require_once __DIR__ . '/../includes/product_images.php';

const INVENTORY_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg', 'image/pjpeg', 'image/x-png'];

function inventory_quantity(mixed $value): int {
    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value <= 0) json_error('Quantity must be a positive whole number.', 422);
    return (int)$value;
}
function inventory_price(mixed $value, string $name): float {
    if (!is_numeric($value) || (float)$value < 0) json_error("{$name} must be a non-negative number.", 422);
    return round((float)$value, 2);
}

/** Canonical retail barcode: digits only when it looks like UPC/EAN; else trimmed raw. */
function inventory_normalize_barcode(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($digits) >= 8 && strlen($digits) <= 14) {
        return $digits;
    }
    return preg_replace('/\s+/', '', $raw) ?? $raw;
}

/**
 * Camera scanners often return EAN-13 (leading 0) while shelves/DB keep UPC-A (12 digits).
 * Build every common equivalent so lookup hits existing inventory.
 */
function inventory_barcode_variants(string $raw): array {
    $trimmed = trim($raw);
    $normalized = inventory_normalize_barcode($raw);
    $variants = [];
    foreach ([$trimmed, $normalized] as $value) {
        if ($value !== '') {
            $variants[] = $value;
        }
    }
    $digits = preg_replace('/\D+/', '', $normalized !== '' ? $normalized : $trimmed) ?? '';
    if ($digits !== '') {
        $variants[] = $digits;
        if (strlen($digits) === 12) {
            $variants[] = '0' . $digits;
        }
        if (strlen($digits) === 13 && str_starts_with($digits, '0')) {
            $variants[] = substr($digits, 1);
        }
        // Scanners drop/add leading zeros — always try UPC-A / EAN-13 padded forms
        $stripped = ltrim($digits, '0');
        $core = $stripped !== '' ? $stripped : $digits;
        $variants[] = $core;
        if (strlen($core) <= 12) {
            $variants[] = str_pad($core, 12, '0', STR_PAD_LEFT);
            $variants[] = str_pad($core, 13, '0', STR_PAD_LEFT);
        }
        if (strlen($digits) <= 12) {
            $variants[] = str_pad($digits, 12, '0', STR_PAD_LEFT);
            $variants[] = str_pad($digits, 13, '0', STR_PAD_LEFT);
        }
    }
    $variants = array_values(array_unique(array_filter($variants, static fn($v) => $v !== '' && mb_strlen($v) <= 100)));
    return $variants;
}

function inventory_find_by_barcode(PDO $pdo, int $storeId, string $barcode): ?array {
    $variants = inventory_barcode_variants($barcode);
    if (!$variants) {
        return null;
    }

    $placeholders = implode(',', array_fill(0, count($variants), '?'));
    $stmt = $pdo->prepare("SELECT * FROM inventory WHERE store_id = ? AND barcode IN ({$placeholders}) LIMIT 1");
    $stmt->execute(array_merge([$storeId], $variants));
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    // Fallback: compare digit-normalized forms (handles SCAN prefixes / spaced barcodes in DB)
    $digitVariants = [];
    foreach ($variants as $v) {
        $d = preg_replace('/\D+/', '', $v) ?? '';
        if ($d !== '') {
            $digitVariants[$d] = true;
            $digitVariants[ltrim($d, '0')] = true;
        }
    }
    unset($digitVariants['']);
    if (!$digitVariants) {
        return null;
    }

    // Postgres rejects MySQL-style "" empty-string literals — use ''.
    $stmt = $pdo->prepare("SELECT * FROM inventory WHERE store_id = ? AND barcode IS NOT NULL AND TRIM(barcode) <> ''");
    $stmt->execute([$storeId]);
    while ($row = $stmt->fetch()) {
        $storedVariants = inventory_barcode_variants((string)($row['barcode'] ?? ''));
        foreach ($storedVariants as $sv) {
            if (in_array($sv, $variants, true)) {
                return $row;
            }
            $sd = preg_replace('/\D+/', '', $sv) ?? '';
            if ($sd !== '' && (isset($digitVariants[$sd]) || isset($digitVariants[ltrim($sd, '0')]))) {
                return $row;
            }
        }
    }
    return null;
}

function inventory_get(PDO $pdo, int $storeId, array $data): array {
    if (!empty($data['id'])) {
        $stmt = $pdo->prepare('SELECT * FROM inventory WHERE id = ?');
        $stmt->execute([(int) $data['id']]);
        $product = $stmt->fetch();
        if (!$product) {
            json_error('Product not found.', 404);
        }
        auth_require_store_access((int) $product['store_id']);
        return $product;
    }
    $barcode = trim((string)($data['barcode'] ?? ''));
    if ($barcode === '' || mb_strlen($barcode) > 100) json_error('Provide a valid product id or barcode.', 422);
    $product = inventory_find_by_barcode($pdo, $storeId, $barcode);
    if (!$product) json_error('Product not found.', 404);
    return $product;
}

/** Normalize browser/PHP MIME quirks → jpeg|png|webp. */
function inventory_normalize_mime(?string $mime, string $filename = '', string $tmpPath = ''): ?string {
    $mime = strtolower(trim((string)$mime));
    if (in_array($mime, ['image/jpg', 'image/pjpeg', 'image/jpeg'], true)) return 'image/jpeg';
    if (in_array($mime, ['image/x-png', 'image/png'], true)) return 'image/png';
    if ($mime === 'image/webp') return 'image/webp';

    $byName = gemini_mime_for_filename($filename);
    if (in_array($byName, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return $byName;
    }

    // Magic bytes when MIME is empty / octet-stream (common on mobile camera uploads)
    if ($tmpPath !== '' && is_readable($tmpPath)) {
        $head = @file_get_contents($tmpPath, false, null, 0, 16);
        if (is_string($head)) {
            if (str_starts_with($head, "\xFF\xD8\xFF")) return 'image/jpeg';
            if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) return 'image/png';
            if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') return 'image/webp';
        }
        $info = @getimagesize($tmpPath);
        if (is_array($info) && !empty($info['mime'])) {
            return inventory_normalize_mime((string)$info['mime'], $filename, '');
        }
    }
    return null;
}

/**
 * Validate upload; optionally recompress oversized images to JPEG via GD
 * so phone camera photos survive PHP upload limits and Gemini.
 */
function inventory_image(array $file): array {
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        $msg = match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Image is too large for the server. Try a smaller photo.',
            UPLOAD_ERR_PARTIAL => 'Image upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE => 'No image was received. Take or choose a photo again.',
            default => 'A valid image upload is required.',
        };
        json_error($msg, 422);
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    // Accept is_uploaded_file OR a readable tmp (some mobile/proxy paths fail the flag)
    if ($tmp === '' || !is_readable($tmp) || (!is_uploaded_file($tmp) && !is_file($tmp))) {
        json_error('No image was received. Take or choose a photo again.', 422);
    }
    if ($size <= 0) {
        $size = (int)@filesize($tmp);
    }
    if ($size <= 0) {
        json_error('The image file is empty. Take the photo again.', 422);
    }
    if ($size > MAX_UPLOAD_SIZE) {
        // ponytail: try GD recompress before rejecting big phone photos
        $shrunk = inventory_recompress_upload($tmp, (string)($file['name'] ?? 'photo.jpg'));
        if ($shrunk === null) {
            json_error('Image is too large. Use a clearer closer photo (under 10MB).', 422);
        }
        return $shrunk;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detected = $finfo ? finfo_file($finfo, $tmp) : false;
    if ($finfo) finfo_close($finfo);
    $mime = inventory_normalize_mime(
        is_string($detected) ? $detected : (string)($file['type'] ?? ''),
        (string)($file['name'] ?? ''),
        $tmp
    );
    if ($mime === null) {
        json_error('Allowed image types: JPEG, PNG, WEBP. If you used an iPhone Live/HEIC photo, take a new photo with the in-app camera.', 422);
    }

    // Soft dimension check — skip hard fail if MIME/magic already OK (some webp variants trip getimagesize)
    $info = @getimagesize($tmp);
    if ($info === false && !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        json_error('Allowed image types: JPEG, PNG, WEBP.', 422);
    }

    // Downscale huge megapixel phone shots for a more reliable AI parse
    if (is_array($info) && (($info[0] ?? 0) > 2000 || ($info[1] ?? 0) > 2000 || $size > 2_500_000)) {
        $shrunk = inventory_recompress_upload($tmp, (string)($file['name'] ?? 'photo.jpg'));
        if ($shrunk !== null) {
            return $shrunk;
        }
    }

    $file['type'] = $mime;
    return [$mime, $file];
}

/** @return array{0:string,1:array}|null [mime, file] */
function inventory_recompress_upload(string $tmpPath, string $filename): ?array {
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }
    $raw = @file_get_contents($tmpPath);
    if ($raw === false || $raw === '') {
        return null;
    }
    $src = @imagecreatefromstring($raw);
    if ($src === false) {
        return null;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1) {
        imagedestroy($src);
        return null;
    }
    $max = 1600;
    $scale = min(1.0, $max / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    if ($dst === false) {
        imagedestroy($src);
        return null;
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);
    $out = tempnam(sys_get_temp_dir(), 'invimg');
    if ($out === false) {
        imagedestroy($dst);
        return null;
    }
    if (!imagejpeg($dst, $out, 85)) {
        imagedestroy($dst);
        @unlink($out);
        return null;
    }
    imagedestroy($dst);
    $newSize = (int)@filesize($out);
    if ($newSize <= 0 || $newSize > MAX_UPLOAD_SIZE) {
        @unlink($out);
        return null;
    }
    // Replace upload tmp so later move_uploaded_file paths still work for parse (read-only)
    if (!@copy($out, $tmpPath)) {
        // parse uses tmp_name directly; keep alternate path in file array
        $file = [
            'name' => preg_replace('/\.[^.]+$/', '', $filename) . '.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $out,
            'error' => UPLOAD_ERR_OK,
            'size' => $newSize,
            '_inventory_temp' => true,
        ];
        return ['image/jpeg', $file];
    }
    @unlink($out);
    return ['image/jpeg', [
        'name' => preg_replace('/\.[^.]+$/', '', $filename) . '.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => $tmpPath,
        'error' => UPLOAD_ERR_OK,
        'size' => (int)@filesize($tmpPath),
    ]];
}
function inventory_store_image(array $file): string {
    [, $file] = inventory_image($file);
    $path = upload_file($file, 'inventory');
    if ($path === false) json_error('Unable to store image.', 500);
    return $path;
}
function inventory_movement(PDO $pdo, array $product, int $storeId, int $userId, string $type, int $quantity, ?float $cost, ?float $price, float $taxRate, float $taxAmount, float $total, string $notes): void {
    $unitPrice = $price ?? $cost;
    $pdo->prepare('INSERT INTO inventory_movements (store_id, inventory_id, movement_type, quantity, unit_price, tax_rate, tax_amount, total_amount, barcode, notes, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([
            $storeId,
            (int) $product['id'],
            $type,
            $quantity,
            $unitPrice,
            $taxRate,
            $taxAmount,
            $total,
            $product['barcode'] ?? null,
            $notes !== '' ? $notes : null,
            $userId,
        ]);
}

/** Admin inventory list: single store or all stores (?store_id=all). */
function inventory_list_scope(mixed $raw): array {
    if (auth_is_admin() && is_string($raw) && strtolower(trim($raw)) === 'all') {
        return ['all' => true, 'store_id' => null];
    }
    $requested = null;
    if ($raw !== null && $raw !== '' && !(is_string($raw) && strtolower(trim($raw)) === 'all')) {
        $requested = (int) $raw;
    }
    return ['all' => false, 'store_id' => resolve_store_id($requested > 0 ? $requested : null)];
}

function inventory_parse_active(array $data, bool $default = true): bool {
    if (!array_key_exists('active', $data)) {
        return $default;
    }
    $value = $data['active'];
    if ($value === true || $value === 1 || $value === '1' || $value === 'on' || $value === 'true') {
        return true;
    }
    if ($value === false || $value === 0 || $value === '0' || $value === 'off' || $value === 'false') {
        return false;
    }
    return (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function inventory_normalize_record_id(array &$data): void {
    if (empty($data['id']) && !empty($data['product_id'])) {
        $data['id'] = $data['product_id'];
    }
}

function inventory_present(array $row): array {
    $row['name'] = $row['product_name'] ?? ($row['name'] ?? '');
    $path = trim((string) ($row['image_path'] ?? ''));
    $row['image_url'] = $path !== '' ? stored_file_url($path) : '';
    $paths = [];
    if (!empty($row['image_paths_json'])) {
        $decoded = json_decode((string) $row['image_paths_json'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $p) {
                $p = trim((string) $p);
                if ($p !== '') {
                    $paths[] = stored_file_url($p);
                }
            }
        }
    }
    if ($row['image_url'] !== '' && !in_array($row['image_url'], $paths, true)) {
        array_unshift($paths, $row['image_url']);
    }
    $row['images'] = $paths;
    $row['active'] = (int) ($row['active'] ?? 1);
    $row['published'] = $row['active'] === 1;
    return $row;
}

function inventory_first_upload(): ?array {
    foreach (['image', 'file'] as $key) {
        if (!empty($_FILES[$key]) && is_array($_FILES[$key]) && !is_array($_FILES[$key]['name'] ?? null)) {
            return $_FILES[$key];
        }
    }
    if (empty($_FILES['images']) || !is_array($_FILES['images']['name'] ?? null)) {
        return !empty($_FILES['images']) && is_array($_FILES['images']) ? $_FILES['images'] : null;
    }
    $names = $_FILES['images']['name'];
    for ($i = 0, $n = count($names); $i < $n; $i++) {
        if (($names[$i] ?? '') === '' || (int) ($_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        return [
            'name' => $_FILES['images']['name'][$i],
            'type' => $_FILES['images']['type'][$i] ?? '',
            'tmp_name' => $_FILES['images']['tmp_name'][$i],
            'error' => $_FILES['images']['error'][$i],
            'size' => $_FILES['images']['size'][$i] ?? 0,
        ];
    }
    return null;
}

function inventory_store_uploads(): array {
    $paths = [];
    $first = inventory_first_upload();
    if ($first) {
        // Prefer collecting every images[] file when present.
        if (!empty($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
            for ($i = 0, $n = count($_FILES['images']['name']); $i < $n; $i++) {
                if (($_FILES['images']['name'][$i] ?? '') === '' || (int) ($_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $file = [
                    'name' => $_FILES['images']['name'][$i],
                    'type' => $_FILES['images']['type'][$i] ?? '',
                    'tmp_name' => $_FILES['images']['tmp_name'][$i],
                    'error' => $_FILES['images']['error'][$i],
                    'size' => $_FILES['images']['size'][$i] ?? 0,
                ];
                $paths[] = inventory_store_image($file);
            }
            return $paths;
        }
        $paths[] = inventory_store_image($first);
    }
    return $paths;
}

$user = auth_require();
$method = get_method();
$pdo = db();

if ($method === 'GET') {
    if (($_GET['action'] ?? '') === 'settings') json_response(['tax_rate' => inventory_global_tax_rate(), 'tax_label' => inventory_tax_label()]);
    // Fast tax feed for Finances / CPA (same period params as /api/finances)
    if (($_GET['action'] ?? '') === 'tax_summary') {
        $scope = inventory_list_scope($_GET['store_id'] ?? null);
        $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
        $dateTo = $_GET['date_to'] ?? date('Y-m-t');
        $sql = "SELECT COUNT(*) AS sale_count,
            COALESCE(SUM(unit_price * ABS(quantity)),0) AS subtotal,
            COALESCE(SUM(tax_amount),0) AS tax_collected,
            COALESCE(SUM(total_amount),0) AS gross
         FROM inventory_movements
         WHERE movement_type='sale'
           AND COALESCE(status, 'active') = 'active'
           AND created_at >= ? AND created_at <= ?";
        $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
        if (!$scope['all']) {
            $sql .= ' AND store_id = ?';
            $params[] = $scope['store_id'];
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];
        json_response([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'tax_rate' => inventory_global_tax_rate(),
            'tax_label' => inventory_tax_label(),
            'sale_count' => (int)($row['sale_count'] ?? 0),
            'subtotal' => round((float)($row['subtotal'] ?? 0), 2),
            'tax_collected' => round((float)($row['tax_collected'] ?? 0), 2),
            'gross' => round((float)($row['gross'] ?? 0), 2),
            'finances_url' => '/finances?period=custom&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo),
        ]);
    }
    $scope = inventory_list_scope($_GET['store_id'] ?? null);
    $search = trim((string)($_GET['search'] ?? ''));
    if ($scope['all']) {
        $where = 'WHERE 1=1';
        $params = [];
    } else {
        $where = 'WHERE i.store_id = ?';
        $params = [$scope['store_id']];
    }
    if ($search !== '') { $where .= ' AND (i.product_name LIKE ? OR i.barcode LIKE ?)'; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }
    $stmt = $pdo->prepare("SELECT i.*, s.name AS store_name FROM inventory i LEFT JOIN stores s ON s.id = i.store_id {$where} ORDER BY s.name, i.product_name LIMIT 500");
    $stmt->execute($params);
    $products = array_map('inventory_present', $stmt->fetchAll());
    if ($scope['all']) {
        $low = $pdo->query('SELECT COUNT(*) AS cnt FROM inventory WHERE quantity <= low_stock_threshold');
        $storeName = 'All stores';
    } else {
        $low = $pdo->prepare('SELECT COUNT(*) AS cnt FROM inventory WHERE quantity <= low_stock_threshold AND store_id = ?');
        $low->execute([$scope['store_id']]);
        $storeStmt = $pdo->prepare('SELECT name FROM stores WHERE id = ?');
        $storeStmt->execute([$scope['store_id']]);
        $storeName = (string) ($storeStmt->fetchColumn() ?: '');
    }
    $totalUnits = 0;
    foreach ($products as $p) {
        $totalUnits += (int) ($p['quantity'] ?? 0);
    }
    json_response([
        'products' => $products,
        'total_products' => count($products),
        'total_units' => $totalUnits,
        'low_stock' => (int) $low->fetch()['cnt'],
        'scope' => $scope['all'] ? 'all' : 'store',
        'store_id' => $scope['all'] ? null : $scope['store_id'],
        'store_name' => $storeName,
        'tax_rate' => inventory_global_tax_rate(),
        'tax_label' => inventory_tax_label(),
    ]);
}
if ($method !== 'POST') json_error('Method not allowed', 405);
csrf_verify();
$multipart = str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data');
$data = $multipart ? $_POST : get_json_body();
inventory_normalize_record_id($data);
$action = (string)($data['action'] ?? '');

if ($action === 'parse_product_image') {
    if (!gemini_configured()) json_error('AI product parser is not configured. Set GEMINI_API_KEY in server environment.', 503);
    $file = inventory_first_upload();
    if (!$file) json_error('An image is required. Take a photo or choose a JPEG/PNG from your gallery.', 422);
    [$mime, $file] = inventory_image($file);
    try {
        $parsed = gemini_parse_product_image($file['tmp_name'], $mime, basename((string)$file['name']));
    } catch (Throwable $e) {
        error_log('inventory parse image: ' . $e->getMessage());
        json_error('Could not read the product from that photo. Try a clearer, closer shot of the label.', 502);
    } finally {
        if (!empty($file['_inventory_temp']) && !empty($file['tmp_name']) && is_file($file['tmp_name'])) {
            @unlink($file['tmp_name']);
        }
    }
    $barcode = trim((string) ($data['barcode'] ?? ''));
    // Prefer barcode from package if AI found one and form barcode empty
    if ($barcode === '' && !empty($parsed['barcode'])) {
        $barcode = trim((string)$parsed['barcode']);
    }
    $query = trim((string) ($parsed['image_search_query'] ?? ($parsed['product_name'] ?? '')));
    $brand = trim((string) ($parsed['brand'] ?? ''));
    $suggested = [];
    try {
        $suggested = product_images_suggest($query, $barcode !== '' ? $barcode : null, 8, $brand !== '' ? $brand : null);
    } catch (Throwable $e) {
        error_log('inventory suggest images: ' . $e->getMessage());
    }
    json_response([
        'success' => true,
        'barcode' => $barcode,
        'product' => $parsed,
        'image_search_query' => $query,
        'suggested_images' => $suggested,
    ]);
}
if ($action === 'suggest_web_images') {
    $query = trim((string) ($data['query'] ?? ($data['image_search_query'] ?? ($data['product_name'] ?? ($data['name'] ?? '')))));
    $barcode = trim((string) ($data['barcode'] ?? ''));
    $brand = trim((string) ($data['brand'] ?? ''));
    if ($query === '' && $barcode === '') json_error('Provide a product name or barcode to search images.', 422);
    try {
        $suggested = product_images_suggest($query, $barcode !== '' ? $barcode : null, 8, $brand !== '' ? $brand : null);
    } catch (Throwable $e) {
        error_log('inventory suggest images: ' . $e->getMessage());
        json_error('Unable to search web images right now.', 502);
    }
    json_response(['success' => true, 'query' => $query, 'suggested_images' => $suggested]);
}
if ($action === 'import_web_image') {
    $url = trim((string) ($data['url'] ?? ($data['image_url'] ?? '')));
    if ($url === '') json_error('An image URL is required.', 422);
    try {
        $path = product_images_import_url($url);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 422);
    } catch (Throwable $e) {
        error_log('inventory import web image: ' . $e->getMessage());
        json_error('Unable to import that web image.', 502);
    }
    json_response([
        'success' => true,
        'image_path' => $path,
        'image_url' => stored_file_url($path),
    ], 201);
}
if ($action === 'upload_image') {
    $file = inventory_first_upload();
    if (!$file) json_error('An image is required.', 422);
    json_response(['success' => true, 'image_path' => inventory_store_image($file)], 201);
}
if ($action === 'settings' || $action === 'update_tax_settings') {
    auth_require_admin();
    $rawRate = $data['tax_rate'] ?? $data['global_tax_rate'] ?? null;
    $rate = inventory_price($rawRate, 'tax_rate');
    if ($rate > 100) json_error('Tax rate cannot exceed 100.', 422);
    inventory_set_tax_settings($rate, (string)($data['tax_label'] ?? 'Sales Tax'));
    json_response(['success' => true, 'tax_rate' => inventory_global_tax_rate(), 'tax_label' => inventory_tax_label()]);
}

$storeId = resolve_store_id(!empty($data['store_id']) ? (int)$data['store_id'] : null);
if ($action === 'lookup_barcode') {
    $barcode = trim((string)($data['barcode'] ?? ''));
    if ($barcode === '' || mb_strlen($barcode) > 100) json_error('A valid barcode is required.', 422);
    $product = inventory_find_by_barcode($pdo, $storeId, $barcode);
    json_response([
        'found' => (bool) $product,
        'product' => $product ? inventory_present($product) : null,
        'matched_barcode' => $product['barcode'] ?? null,
        'scanned_barcode' => inventory_normalize_barcode($barcode) ?: $barcode,
        'tax_rate' => inventory_global_tax_rate(),
        'tax_label' => inventory_tax_label(),
    ]);
}
if ($action === 'create' || $action === 'update') {
    if (empty($data['product_name']) && !empty($data['name'])) {
        $data['product_name'] = $data['name'];
    }
    validate_required($data, ['product_name']);
    $barcode = inventory_normalize_barcode((string) ($data['barcode'] ?? ''));
    if (mb_strlen($barcode) > 100) json_error('Barcode is too long.', 422);
    $uploaded = inventory_store_uploads();
    $imagePath = $uploaded[0] ?? trim((string) ($data['image_path'] ?? ''));
    $imagePathsJson = $uploaded !== [] ? json_encode($uploaded, JSON_UNESCAPED_SLASHES) : null;
    if ($imagePath !== '' && !str_starts_with($imagePath, 'assets/uploads/inventory/') && !storage_is_remote($imagePath)) {
        json_error('Invalid image path.', 422);
    }
    // Every retail product is taxable — sales always collect the store tax rate.
    $taxable = 1;
    $categoryInput = array_key_exists('category', $data) ? sanitize((string) $data['category']) : null;
    $active = inventory_parse_active($data, true);
    $quantity = max(0, (int) ($data['quantity'] ?? 0));
    try {
        if ($action === 'create') {
            $category = ($categoryInput !== null && $categoryInput !== '') ? $categoryInput : null;
            $stmt = $pdo->prepare('INSERT INTO inventory (store_id, product_name, barcode, quantity, description, category, cost_price, retail_price, low_stock_threshold, image_path, image_paths_json, taxable, active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([
                $storeId,
                sanitize((string) $data['product_name']),
                $barcode !== '' ? $barcode : null,
                $quantity,
                sanitize((string) ($data['description'] ?? '')),
                $category,
                inventory_price($data['cost_price'] ?? 0, 'cost_price'),
                inventory_price($data['retail_price'] ?? 0, 'retail_price'),
                max(0, (int) ($data['low_stock_threshold'] ?? 5)),
                $imagePath !== '' ? $imagePath : null,
                $imagePathsJson,
                $taxable,
                $active ? 1 : 0,
            ]);
            json_response(['success' => true, 'id' => sql_last_insert_id($pdo, 'inventory')], 201);
        }
        validate_required($data, ['id']);
        $product = inventory_get($pdo, $storeId, ['id' => $data['id']]);
        $productStoreId = (int) $product['store_id'];
        if ($categoryInput !== null && $categoryInput !== '') {
            $category = $categoryInput;
        } else {
            $category = $product['category'] ?? null;
        }
        $sql = 'UPDATE inventory SET product_name=?, barcode=?, description=?, category=?, cost_price=?, retail_price=?, quantity=?, low_stock_threshold=?, taxable=?, active=?';
        $params = [
            sanitize((string) $data['product_name']),
            $barcode !== '' ? $barcode : null,
            sanitize((string) ($data['description'] ?? '')),
            $category !== null && $category !== '' ? $category : null,
            inventory_price($data['cost_price'] ?? 0, 'cost_price'),
            inventory_price($data['retail_price'] ?? 0, 'retail_price'),
            $quantity,
            max(0, (int) ($data['low_stock_threshold'] ?? 5)),
            $taxable,
            $active ? 1 : 0,
        ];
        if ($imagePath !== '') {
            $sql .= ', image_path=?';
            $params[] = $imagePath;
        }
        if ($imagePathsJson !== null) {
            $sql .= ', image_paths_json=?';
            $params[] = $imagePathsJson;
        }
        $sql .= ' WHERE id=? AND store_id=?';
        $params[] = $product['id'];
        $params[] = $productStoreId;
        $pdo->prepare($sql)->execute($params);
        json_response(['success' => true, 'id' => (int) $product['id']]);
    } catch (PDOException $e) {
        if (str_contains(strtolower($e->getMessage()), 'barcode')) json_error('That barcode already exists for this store.', 409);
        throw $e;
    }
}
if ($action === 'stock_in') {
    $product = inventory_get($pdo, $storeId, $data);
    $productStoreId = (int) $product['store_id'];
    $quantity = inventory_quantity($data['quantity'] ?? null);
    $cost = array_key_exists('cost_price', $data) ? inventory_price($data['cost_price'], 'cost_price') : (float)($product['cost_price'] ?? 0);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE inventory SET quantity=quantity+?, cost_price=? WHERE id=? AND store_id=?')
            ->execute([$quantity, $cost, $product['id'], $productStoreId]);
        inventory_movement($pdo, $product, $productStoreId, (int)$user['id'], 'stock_in', $quantity, $cost, null, 0, 0, 0, sanitize((string)($data['notes'] ?? '')));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    json_response(['success' => true]);
}
if ($action === 'sale') {
    $product = inventory_get($pdo, $storeId, $data);
    $productStoreId = (int) $product['store_id'];
    $quantity = inventory_quantity($data['quantity'] ?? null);
    $price = array_key_exists('retail_price', $data) ? inventory_price($data['retail_price'], 'retail_price') : (float)($product['retail_price'] ?? 0);
    // Always collect sales tax on every sale (Texas store rate from settings).
    $subtotal = round($price * $quantity, 2);
    $taxRate = inventory_global_tax_rate();
    $tax = round($subtotal * $taxRate / 100, 2);
    $total = round($subtotal + $tax, 2);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE inventory SET quantity=quantity-? WHERE id=? AND store_id=? AND quantity>=?');
        $stmt->execute([$quantity, $product['id'], $productStoreId, $quantity]);
        if ($stmt->rowCount() !== 1) { $pdo->rollBack(); json_error('Insufficient stock.', 409); }
        inventory_movement($pdo, $product, $productStoreId, (int)$user['id'], 'sale', -$quantity, null, $price, $taxRate, $tax, $total, sanitize((string)($data['notes'] ?? '')));
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    json_response(['success' => true, 'subtotal' => $subtotal, 'tax_rate' => $taxRate, 'tax_amount' => $tax, 'total' => $total]);
}
if ($action === 'set_publish') {
    validate_required($data, ['id']);
    $publish = array_key_exists('publish', $data)
        ? (filter_var($data['publish'], FILTER_VALIDATE_BOOLEAN) || $data['publish'] === 1 || $data['publish'] === '1')
        : (!array_key_exists('active', $data) || filter_var($data['active'], FILTER_VALIDATE_BOOLEAN) || $data['active'] === 1 || $data['active'] === '1');
    $product = inventory_get($pdo, $storeId, $data);
    $productStoreId = (int) $product['store_id'];
    $pdo->prepare('UPDATE inventory SET active=? WHERE id=? AND store_id=?')
        ->execute([$publish ? 1 : 0, (int) $product['id'], $productStoreId]);
    json_response(['success' => true, 'active' => $publish ? 1 : 0, 'published' => $publish]);
}
if ($action === 'delete') {
    validate_required($data, ['id']);
    $product = inventory_get($pdo, $storeId, ['id' => $data['id']]);
    $pdo->prepare('DELETE FROM inventory WHERE id=? AND store_id=?')->execute([(int) $product['id'], (int) $product['store_id']]);
    json_response(['success' => true]);
}
json_error('Unknown action');
