<?php

require_once __DIR__ . '/../includes/catalog.php';

if (get_method() !== 'GET') {
    json_error('Method not allowed', 405);
}

$pdo = db();
$storeId = !empty($_GET['store_id']) ? (int) $_GET['store_id'] : null;
$search = trim((string) ($_GET['search'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));

$stores = catalog_fetch_published_stores($pdo);
if ($storeId !== null && $storeId > 0) {
    $allowed = array_column($stores, 'id');
    if (!in_array($storeId, array_map('intval', $allowed), true)) {
        json_error('Store not found or not published', 404);
    }
}

$products = catalog_fetch_products($pdo, $storeId, $search, $category);
$categories = catalog_fetch_categories($pdo, $storeId);
$pricesVisible = false;
foreach ($products as $product) {
    if (array_key_exists('retail_price', $product)) {
        $pricesVisible = true;
        break;
    }
}

json_response([
    'products' => $products,
    'stores' => array_map(static function (array $s): array {
        return [
            'id' => (int) $s['id'],
            'name' => (string) $s['name'],
            'address' => (string) ($s['address'] ?? ''),
            'phone' => (string) ($s['phone'] ?? ''),
        ];
    }, $stores),
    'categories' => $categories,
    'total_products' => count($products),
    'prices_visible' => $pricesVisible,
]);
