<?php

require_once __DIR__ . '/../includes/catalog.php';
require_once __DIR__ . '/../includes/gemini.php';

if (get_method() !== 'POST') {
    json_error('Method not allowed', 405);
}

$data = get_json_body();
$message = trim((string) ($data['message'] ?? ''));
if ($message === '') {
    json_error('Message is required.', 422);
}
if (mb_strlen($message) > 2000) {
    json_error('Message is too long.', 422);
}

$ip = catalog_client_ip();
if (!catalog_rate_limit_check('catalog-chat:' . $ip, 20, 600)) {
    json_error('Too many requests. Please wait a few minutes and try again.', 429);
}

if (!gemini_configured()) {
    json_error('Product advisor is not available right now.', 503);
}

$pdo = db();
$stores = catalog_fetch_published_stores($pdo);
if ($stores === []) {
    json_error('No published inventory is available.', 503);
}

$products = catalog_fetch_products($pdo);
$snapshot = catalog_compact_snapshot($products);
$history = [];
foreach ((array) ($data['history'] ?? []) as $turn) {
    if (!is_array($turn)) {
        continue;
    }
    $role = strtolower(trim((string) ($turn['role'] ?? '')));
    $content = trim((string) ($turn['content'] ?? ''));
    if ($content === '' || !in_array($role, ['user', 'assistant'], true)) {
        continue;
    }
    $history[] = ['role' => $role, 'content' => mb_substr($content, 0, 2000)];
    if (count($history) >= 10) {
        break;
    }
}

try {
    $result = gemini_inventory_advisor($message, $snapshot, $history);
} catch (InvalidArgumentException $e) {
    json_error($e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('catalog-chat: ' . $e->getMessage());
    json_error('Unable to get a response right now. Please try again.', 502);
}

$byId = [];
foreach ($products as $product) {
    $byId[(int) $product['id']] = $product;
}
$suggested = [];
foreach ($result['suggested_product_ids'] as $id) {
    if (isset($byId[$id])) {
        $suggested[] = $byId[$id];
    }
}

json_response([
    'reply' => $result['reply'],
    'suggested_products' => $suggested,
]);
