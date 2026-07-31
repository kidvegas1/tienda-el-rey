<?php

require_once __DIR__ . '/storage.php';

function catalog_bool(mixed $value): bool {
    return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
}

function catalog_public_image_url(string $storedPath): string {
    if ($storedPath === '') {
        return '';
    }
    $path = ltrim(str_replace('\\', '/', $storedPath), '/');
    if (storage_is_remote($storedPath) || str_starts_with($path, 'assets/uploads/inventory/')) {
        $ref = storage_is_remote($storedPath) ? $storedPath : $path;
        return '/api/catalog-image?ref=' . rawurlencode($ref) . '&inline=1';
    }
    return '';
}

function catalog_is_public_image_ref(string $ref): bool {
    if ($ref === '' || str_contains($ref, '..') || str_contains($ref, "\0")) {
        return false;
    }
    $normalized = ltrim(str_replace('\\', '/', $ref), '/');
    if (storage_is_remote($ref)) {
        $parsed = storage_parse_uri($ref);
        return $parsed !== null && $parsed[0] === 'inventory';
    }
    return (bool) preg_match('#^assets/uploads/inventory/[A-Za-z0-9._/-]+$#', $normalized);
}

function catalog_present_product(array $row, bool $includePrice): array {
    $path = trim((string) ($row['image_path'] ?? ''));
    $images = [];
    if ($path !== '') {
        $url = catalog_public_image_url($path);
        if ($url !== '') {
            $images[] = $url;
        }
    }
    if (!empty($row['image_paths_json'])) {
        $decoded = json_decode((string) $row['image_paths_json'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $p) {
                $p = trim((string) $p);
                if ($p === '') {
                    continue;
                }
                $url = catalog_public_image_url($p);
                if ($url !== '' && !in_array($url, $images, true)) {
                    $images[] = $url;
                }
            }
        }
    }

    $product = [
        'id' => (int) ($row['id'] ?? 0),
        'product_name' => (string) ($row['product_name'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'category' => (string) ($row['category'] ?? ''),
        'store_id' => (int) ($row['store_id'] ?? 0),
        'store_name' => (string) ($row['store_name'] ?? ''),
        'in_stock' => true,
        'image_url' => $images[0] ?? '',
        'images' => $images,
    ];

    if ($includePrice && isset($row['retail_price']) && $row['retail_price'] !== null && $row['retail_price'] !== '') {
        $product['retail_price'] = round((float) $row['retail_price'], 2);
    }

    return $product;
}

function catalog_fetch_published_stores(PDO $pdo): array {
    $stmt = $pdo->query(
        'SELECT id, name, address, phone FROM stores WHERE '
        . sql_is_active('stores.active')
        . ' AND ' . sql_is_true('stores.publish_inventory')
        . ' ORDER BY name'
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function catalog_fetch_products(PDO $pdo, ?int $storeId = null, string $search = '', string $category = ''): array {
    $where = 'WHERE ' . sql_is_active('s.active')
        . ' AND ' . sql_is_true('s.publish_inventory')
        . ' AND ' . sql_is_active('i.active')
        . ' AND i.quantity > 0';
    $params = [];

    if ($storeId !== null && $storeId > 0) {
        $where .= ' AND i.store_id = ?';
        $params[] = $storeId;
    }
    if ($search !== '') {
        $where .= ' AND (i.product_name LIKE ? OR i.description LIKE ? OR i.category LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($category !== '') {
        $where .= ' AND i.category = ?';
        $params[] = $category;
    }

    $sql = 'SELECT i.*, s.name AS store_name, s.publish_prices
            FROM inventory i
            INNER JOIN stores s ON s.id = i.store_id
            ' . $where . '
            ORDER BY i.product_name ASC
            LIMIT 1000';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $products = [];
    foreach ($rows as $row) {
        $includePrice = catalog_bool($row['publish_prices'] ?? false);
        $products[] = catalog_present_product($row, $includePrice);
    }
    return $products;
}

function catalog_fetch_categories(PDO $pdo, ?int $storeId = null): array {
    $where = 'WHERE ' . sql_is_active('s.active')
        . ' AND ' . sql_is_true('s.publish_inventory')
        . ' AND ' . sql_is_active('i.active')
        . ' AND i.quantity > 0'
        . " AND COALESCE(i.category, '') <> ''";
    $params = [];
    if ($storeId !== null && $storeId > 0) {
        $where .= ' AND i.store_id = ?';
        $params[] = $storeId;
    }
    $sql = 'SELECT DISTINCT i.category
            FROM inventory i
            INNER JOIN stores s ON s.id = i.store_id
            ' . $where . '
            ORDER BY i.category ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cats = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cat = trim((string) ($row['category'] ?? ''));
        if ($cat !== '') {
            $cats[] = $cat;
        }
    }
    return $cats;
}

function catalog_compact_snapshot(array $products): array {
    return array_map(static function (array $p): array {
        $item = [
            'id' => $p['id'],
            'product_name' => $p['product_name'],
            'description' => $p['description'],
            'category' => $p['category'],
            'store_name' => $p['store_name'],
        ];
        if (isset($p['retail_price'])) {
            $item['retail_price'] = $p['retail_price'];
        }
        return $item;
    }, $products);
}

function catalog_rate_limit_check(string $key, int $maxRequests = 20, int $windowSeconds = 600): bool {
    $dir = sys_get_temp_dir() . '/tienda-el-rey-catalog-chat';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return true;
    }
    $file = $dir . '/' . hash('sha256', $key) . '.json';
    $now = time();
    $data = ['count' => 0, 'reset' => $now + $windowSeconds];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $decoded = $raw ? json_decode($raw, true) : null;
        if (is_array($decoded) && ($decoded['reset'] ?? 0) > $now) {
            $data = $decoded;
        }
    }
    if (($data['count'] ?? 0) >= $maxRequests) {
        return false;
    }
    $data['count'] = (int) ($data['count'] ?? 0) + 1;
    if (($data['reset'] ?? 0) <= $now) {
        $data['reset'] = $now + $windowSeconds;
        $data['count'] = 1;
    }
    @file_put_contents($file, json_encode($data), LOCK_EX);
    return true;
}

function catalog_client_ip(): string {
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];
    foreach ($candidates as $value) {
        $value = trim(explode(',', (string) $value)[0]);
        if ($value !== '') {
            return $value;
        }
    }
    return 'unknown';
}
