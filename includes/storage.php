<?php

/** Stored path prefix for Supabase Storage objects in the database. */
const STORAGE_URI_PREFIX = 'storage://';

/**
 * True when Supabase Storage uploads are configured (server-side service role).
 */
function storage_enabled(): bool {
    return SUPABASE_URL !== '' && SUPABASE_SERVICE_ROLE_KEY !== '';
}

/**
 * Map local upload subdirs to Supabase Storage bucket names.
 */
function storage_bucket_for_subdir(string $subdir): string {
    return match (rtrim($subdir, '/')) {
        'barri-reports' => 'barri-reports',
        'imports'       => 'imports',
        default         => 'client-ids',
    };
}

/**
 * Parse storage://bucket/object/path into [bucket, objectPath] or null.
 */
function storage_parse_uri(string $storedPath): ?array {
    if (!str_starts_with($storedPath, STORAGE_URI_PREFIX)) {
        return null;
    }
    $rest = substr($storedPath, strlen(STORAGE_URI_PREFIX));
    $slash = strpos($rest, '/');
    if ($slash === false || $slash === 0) {
        return null;
    }
    return [
        substr($rest, 0, $slash),
        substr($rest, $slash + 1),
    ];
}

function storage_is_remote(string $storedPath): bool {
    return storage_parse_uri($storedPath) !== null;
}

/** True when a stored path points at a known upload subdir (local disk or Supabase bucket). */
function storage_path_in_subdir(string $storedPath, string $subdir): bool {
    $subdir = trim($subdir, '/');
    if ($subdir === '') {
        return false;
    }
    $normalized = ltrim(str_replace('\\', '/', $storedPath), '/');
    if (str_starts_with($normalized, 'assets/uploads/' . $subdir . '/')) {
        return true;
    }
    $parsed = storage_parse_uri($storedPath);
    if ($parsed === null) {
        return false;
    }
    [$bucket, $objectPath] = $parsed;
    return $bucket === storage_bucket_for_subdir($subdir)
        && str_starts_with($objectPath, $subdir . '/');
}

/**
 * Resolve the MIME type sent to Storage.
 *
 * XLSX containers are ZIP archives, so fileinfo commonly reports
 * application/zip. Supabase validates Content-Type against the bucket's
 * allowed MIME types and requires the official spreadsheet type.
 */
function storage_is_spreadsheet_file(string $localPath, string $originalName = ''): bool {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (in_array($ext, ['xlsx', 'xls'], true)) {
        return true;
    }
    $head = @file_get_contents($localPath, false, null, 0, 4);
    if ($head === "PK\x03\x04") {
        return true;
    }
    return $head === "\xD0\xCF\x11\xE0";
}

function storage_upload_content_type(string $localPath, string $originalName = ''): string {
    if (storage_is_spreadsheet_file($localPath, $originalName)) {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        return $ext === 'xls'
            ? 'application/vnd.ms-excel'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    return match ($ext) {
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls' => 'application/vnd.ms-excel',
        default => mime_content_type($localPath) ?: 'application/octet-stream',
    };
}

/** Upload a local file to Supabase Storage. Returns storage:// URI on success. */
function storage_upload(
    string $localPath,
    string $bucket,
    string $objectPath,
    string $originalName = ''
): string {
    if (!is_file($localPath)) {
        throw new RuntimeException('Upload source file not found');
    }

    $objectPath = ltrim(str_replace('\\', '/', $objectPath), '/');
    $url = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/' . rawurlencode($bucket) . '/' . storage_encode_object_path($objectPath);

    $mime = storage_upload_content_type($localPath, $originalName);
    $body = file_get_contents($localPath);
    if ($body === false) {
        throw new RuntimeException('Failed to read upload source file');
    }

    $response = storage_http_request('POST', $url, $body, [
        'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
        'Content-Type: ' . $mime,
        'x-upsert: true',
    ]);

    if ($response['code'] < 200 || $response['code'] >= 300) {
        $detail = trim($response['body']);
        if ($detail !== '') {
            $detail = substr(preg_replace('/\s+/', ' ', $detail) ?? $detail, 0, 240);
            error_log('[storage] upload rejected: HTTP ' . $response['code'] . ' ' . $detail);
        }
        throw new RuntimeException('Supabase Storage upload failed (HTTP ' . $response['code'] . ')');
    }

    return STORAGE_URI_PREFIX . $bucket . '/' . $objectPath;
}

/**
 * Best-effort delete of a stored object. Returns true when removed.
 */
function storage_delete(string $storedPath): bool {
    $parsed = storage_parse_uri($storedPath);
    if ($parsed === null) {
        return false;
    }
    [$bucket, $objectPath] = $parsed;
    $url = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/' . rawurlencode($bucket) . '/' . storage_encode_object_path($objectPath);
    $response = storage_http_request('DELETE', $url, null, [
        'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
    ]);
    return $response['code'] >= 200 && $response['code'] < 300;
}

/**
 * Create a time-limited signed URL for a private object.
 */
function storage_signed_url(string $bucket, string $objectPath, int $expiresSeconds = 3600): string {
    $objectPath = ltrim(str_replace('\\', '/', $objectPath), '/');
    $url = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/sign/' . rawurlencode($bucket) . '/' . storage_encode_object_path($objectPath);

    $response = storage_http_request('POST', $url, json_encode(['expiresIn' => max(1, $expiresSeconds)]), [
        'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
        'Content-Type: application/json',
    ]);

    if ($response['code'] < 200 || $response['code'] >= 300) {
        throw new RuntimeException('Supabase Storage sign failed (HTTP ' . $response['code'] . ')');
    }

    $data = json_decode($response['body'], true);
    $signed = $data['signedURL'] ?? $data['signedUrl'] ?? '';
    if ($signed === '') {
        throw new RuntimeException('Supabase Storage sign response missing signedURL');
    }

    if (str_starts_with($signed, 'http://') || str_starts_with($signed, 'https://')) {
        return $signed;
    }

    return rtrim(SUPABASE_URL, '/') . '/storage/v1' . (str_starts_with($signed, '/') ? $signed : '/' . $signed);
}

/**
 * Browser/API URL for a stored path (local relative path or authenticated proxy).
 */
function stored_file_url(string $storedPath): string {
    if ($storedPath === '') {
        return '';
    }
    $path = ltrim(str_replace('\\', '/', $storedPath), '/');
    // Local uploads are blocked as static files; serve through authenticated /api/files.
    if (storage_is_remote($storedPath) || str_starts_with($path, 'assets/uploads/')) {
        return '/api/files?ref=' . rawurlencode(storage_is_remote($storedPath) ? $storedPath : $path) . '&inline=1';
    }
    return '/' . $path;
}

/**
 * Stream a stored file to the client (local disk or Supabase download).
 *
 * @param array{download_name?: string, content_type?: string, inline?: bool} $options
 */
function storage_serve(string $storedPath, array $options = []): void {
    if ($storedPath === '') {
        json_error('File not found', 404);
    }

    if (!storage_is_remote($storedPath)) {
        $filePath = __DIR__ . '/../' . ltrim($storedPath, '/');
        if (!is_file($filePath)) {
            json_error('File not found on server', 404);
        }
        $mime = $options['content_type'] ?? (mime_content_type($filePath) ?: 'application/octet-stream');
        $disposition = !empty($options['inline']) ? 'inline' : 'attachment';
        $name = $options['download_name'] ?? basename($filePath);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $name) . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }

    $parsed = storage_parse_uri($storedPath);
    if (!$parsed) {
        json_error('Invalid storage path', 400);
    }
    [$bucket, $objectPath] = $parsed;

    $downloadUrl = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/' . rawurlencode($bucket) . '/' . storage_encode_object_path($objectPath);
    $response = storage_http_request('GET', $downloadUrl, null, [
        'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
    ]);

    if ($response['code'] === 404) {
        json_error('File not found in storage', 404);
    }
    if ($response['code'] < 200 || $response['code'] >= 300) {
        json_error('Failed to read file from storage', 502);
    }

    $mime = $options['content_type'] ?? ($response['headers']['content-type'] ?? 'application/octet-stream');
    $disposition = !empty($options['inline']) ? 'inline' : 'attachment';
    $name = $options['download_name'] ?? basename($objectPath);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $name) . '"');
    header('Content-Length: ' . strlen($response['body']));
    echo $response['body'];
    exit;
}

/**
 * Add web URLs for known file path columns on client/receiver records.
 */
function with_stored_file_urls(array $row, array $keys = ['sender_id_path', 'income_doc_path', 'id_path', 'face_photo_path']): array {
    foreach ($keys as $key) {
        if (!empty($row[$key])) {
            $row[$key . '_url'] = stored_file_url((string)$row[$key]);
        }
    }
    return $row;
}

function storage_encode_object_path(string $objectPath): string {
    return implode('/', array_map('rawurlencode', explode('/', $objectPath)));
}

/**
 * @param array<string> $headers
 * @return array{code: int, body: string, headers: array<string, string>}
 */
function storage_http_request(string $method, string $url, ?string $body, array $headers = []): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 120,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Storage HTTP request failed: ' . $err);
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $headerRaw = substr($raw, 0, $headerSize);
        $responseBody = substr($raw, $headerSize);
        return [
            'code'    => $code,
            'body'    => $responseBody,
            'headers' => storage_parse_headers($headerRaw),
        ];
    }

    $headerLines = implode("\r\n", $headers);
    if ($body !== null) {
        $headerLines .= "\r\nContent-Length: " . strlen($body);
    }
    $context = stream_context_create([
        'http' => [
            'method'        => $method,
            'header'        => $headerLines,
            'content'       => $body ?? '',
            'ignore_errors' => true,
            'timeout'       => 120,
        ],
    ]);
    $responseBody = @file_get_contents($url, false, $context);
    if ($responseBody === false) {
        throw new RuntimeException('Storage HTTP request failed');
    }
    $code = 0;
    $rawHeaders = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?: [])
        : ($GLOBALS['http_response_header'] ?? []);
    if (!empty($rawHeaders[0]) && preg_match('/\s(\d{3})\s/', $rawHeaders[0], $m)) {
        $code = (int)$m[1];
    }
    return [
        'code'    => $code,
        'body'    => $responseBody,
        'headers' => storage_parse_headers(implode("\r\n", $rawHeaders)),
    ];
}

/** @return array<string, string> */
function storage_parse_headers(string $raw): array {
    $headers = [];
    foreach (preg_split('/\r\n|\n|\r/', $raw) as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }
    return $headers;
}
