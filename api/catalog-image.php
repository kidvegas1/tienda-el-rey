<?php

require_once __DIR__ . '/../includes/catalog.php';

if (get_method() !== 'GET') {
    json_error('Method not allowed', 405);
}

$ref = trim((string) ($_GET['ref'] ?? ''));
if (!catalog_is_public_image_ref($ref)) {
    json_error('Missing or invalid ref parameter', 400);
}

$normalized = ltrim(str_replace('\\', '/', $ref), '/');
$isRemote = storage_is_remote($ref);
$inline = !isset($_GET['inline']) || $_GET['inline'] !== '0';

header('Cache-Control: public, max-age=86400');
storage_serve($isRemote ? $ref : $normalized, [
    'inline' => $inline,
]);
