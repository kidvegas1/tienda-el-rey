<?php

/**
 * Parse and validate a face descriptor JSON array from the client.
 * @return list<float>|null
 */
function client_face_parse_descriptor(mixed $raw): ?array {
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
    } elseif (is_array($raw)) {
        $decoded = $raw;
    } else {
        return null;
    }
    if (!is_array($decoded) || $decoded === []) {
        return null;
    }
    $out = [];
    foreach ($decoded as $v) {
        if (!is_numeric($v)) {
            return null;
        }
        $f = (float)$v;
        if (!is_finite($f)) {
            return null;
        }
        $out[] = $f;
    }
    // ponytail: Human/face-api descriptors are typically 128–1024 floats
    $n = count($out);
    if ($n < 64 || $n > 2048) {
        return null;
    }
    return $out;
}

function client_face_encode_descriptor(array $descriptor): string {
    return json_encode(array_values($descriptor), JSON_UNESCAPED_SLASHES);
}

/**
 * Whether the clients face columns exist (older DBs before migration).
 */
function client_face_columns_exist(PDO $pdo): bool {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        if (db_is_pgsql()) {
            $stmt = $pdo->query(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = 'clients' AND column_name = 'face_descriptor'
                 LIMIT 1"
            );
        } else {
            $stmt = $pdo->query("SHOW COLUMNS FROM clients LIKE 'face_descriptor'");
        }
        $cached = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

function client_face_thumb_column_exists(PDO $pdo): bool {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        if (db_is_pgsql()) {
            $stmt = $pdo->query(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = 'clients' AND column_name = 'face_photo_thumb'
                 LIMIT 1"
            );
        } else {
            $stmt = $pdo->query("SHOW COLUMNS FROM clients LIKE 'face_photo_thumb'");
        }
        $cached = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * Build a small JPEG data-URL thumbnail for reliable profile display.
 * Returns null when GD is unavailable or the image cannot be read.
 */
function client_face_make_thumb_data_url(string $localPath, int $maxEdge = 320, int $quality = 82): ?string {
    if ($localPath === '' || !is_file($localPath) || !function_exists('imagecreatefromstring')) {
        return null;
    }
    $raw = @file_get_contents($localPath);
    if ($raw === false || $raw === '') {
        return null;
    }
    // Cap source decode size (~8MB) to avoid memory spikes
    if (strlen($raw) > 8_000_000) {
        return null;
    }
    $src = @imagecreatefromstring($raw);
    if ($src === false) {
        return null;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1) {
        return null;
    }
    $scale = min(1.0, $maxEdge / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    if ($dst === false) {
        return null;
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    ob_start();
    imagejpeg($dst, null, $quality);
    $jpeg = ob_get_clean();
    if (!is_string($jpeg) || $jpeg === '') {
        return null;
    }
    // Keep under ~200KB for row size comfort
    if (strlen($jpeg) > 220_000) {
        ob_start();
        imagejpeg($dst, null, 70);
        $jpeg = ob_get_clean();
    }
    if (!is_string($jpeg) || $jpeg === '') {
        return null;
    }
    return 'data:image/jpeg;base64,' . base64_encode($jpeg);
}

/**
 * Attach display URLs for Face ID photo (DB thumb preferred, then stored file URL).
 */
function client_face_with_display_urls(array $client): array {
    $client = with_stored_file_urls($client, ['sender_id_path', 'income_doc_path', 'face_photo_path']);
    $thumb = trim((string)($client['face_photo_thumb'] ?? ''));
    if ($thumb !== '' && str_starts_with($thumb, 'data:image/')) {
        $client['face_photo_display_url'] = $thumb;
    } elseif (!empty($client['face_photo_path_url'])) {
        $client['face_photo_display_url'] = $client['face_photo_path_url'];
    } else {
        $client['face_photo_display_url'] = '';
    }
    // Never send raw base64 thumb twice in large payloads when display_url is set
    unset($client['face_photo_thumb']);
    return $client;
}
