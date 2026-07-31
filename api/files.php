<?php
$user = auth_require();
$method = get_method();

if ($method !== 'GET') {
    json_error('Method not allowed', 405);
}

$ref = trim((string) ($_GET['ref'] ?? ''));
if ($ref === '' || str_contains($ref, '..') || str_contains($ref, "\0")) {
    json_error('Missing or invalid ref parameter', 400);
}

$normalized = ltrim(str_replace('\\', '/', $ref), '/');
$isRemote = storage_is_remote($ref);
$isLocalUpload = str_starts_with($normalized, 'assets/uploads/')
    && (bool) preg_match('#^assets/uploads/[A-Za-z0-9._/-]+$#', $normalized);

if (!$isRemote && !$isLocalUpload) {
    json_error('Invalid storage reference', 400);
}

auth_require_stored_path_access($isRemote ? $ref : $normalized);

$inline = !isset($_GET['inline']) || $_GET['inline'] !== '0';
storage_serve($isRemote ? $ref : $normalized, [
    'inline' => $inline,
]);
