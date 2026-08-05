<?php

/**
 * Face ID enrollment helpers.
 * Pipeline: validate upload → normalize to JPEG → thumb → storage → DB.
 */

/** @return list<float>|null */
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
    $n = count($out);
    if ($n < 64 || $n > 2048) {
        return null;
    }
    return $out;
}

function client_face_encode_descriptor(array $descriptor): string {
    return json_encode(array_values($descriptor), JSON_UNESCAPED_SLASHES);
}

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

/** @return list<string> */
function client_face_allowed_extensions(): array {
    return ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'heif', 'avif', 'jfif'];
}

function client_face_is_heic(string $ext, string $mime, string $rawHead = ''): bool {
    $ext = strtolower($ext);
    if (in_array($ext, ['heic', 'heif'], true)) {
        return true;
    }
    if (str_contains(strtolower($mime), 'heic') || str_contains(strtolower($mime), 'heif')) {
        return true;
    }
    // ftyp....heic / heif / mif1
    return $rawHead !== '' && str_contains($rawHead, 'ftyp') && (
        str_contains($rawHead, 'heic')
        || str_contains($rawHead, 'heif')
        || str_contains($rawHead, 'mif1')
    );
}

/**
 * Fail Face ID enroll with structured JSON for the UI.
 * @param array<string, mixed> $extra
 */
function client_face_fail(string $code, string $message, string $hint = '', int $http = 400, array $extra = []): never {
    error_log('[face] ' . $code . ': ' . $message);
    json_response(array_merge([
        'success' => false,
        'error'   => $message,
        'code'    => $code,
        'hint'    => $hint,
    ], $extra), $http);
}

/**
 * Decode any supported raster into a GD image.
 * @return resource|\GdImage|null
 */
function client_face_gd_from_bytes(string $raw, string $ext = '') {
    if ($raw === '' || !function_exists('imagecreatefromstring')) {
        return null;
    }
    $img = @imagecreatefromstring($raw);
    if ($img !== false) {
        return $img;
    }
    $ext = strtolower($ext);
    $tmp = tempnam(sys_get_temp_dir(), 'facein');
    if ($tmp === false) {
        return null;
    }
    file_put_contents($tmp, $raw);
    $img = null;
    try {
        $img = match ($ext) {
            'jpg', 'jpeg', 'jfif' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($tmp) : false,
            'png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($tmp) : false,
            'gif' => function_exists('imagecreatefromgif') ? @imagecreatefromgif($tmp) : false,
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
            'bmp' => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($tmp) : false,
            default => false,
        };
    } finally {
        @unlink($tmp);
    }
    return $img !== false ? $img : null;
}

/**
 * Normalize an uploaded image to a JPEG temp file for storage + thumbs.
 *
 * @return array{ok:true, path:string, mime:string, bytes:int}|array{ok:false, code:string, error:string, hint:string}
 */
function client_face_normalize_upload(string $tmpPath, string $originalName = '', string $mime = ''): array {
    if ($tmpPath === '' || !is_file($tmpPath)) {
        return [
            'ok' => false,
            'code' => 'no_file',
            'error' => 'No photo file received.',
            'hint' => 'Choose an image again or use the camera.',
        ];
    }
    $size = (int)@filesize($tmpPath);
    if ($size <= 0) {
        return [
            'ok' => false,
            'code' => 'empty_file',
            'error' => 'The image file was empty.',
            'hint' => 'Try another photo.',
        ];
    }
    if ($size > MAX_UPLOAD_SIZE) {
        return [
            'ok' => false,
            'code' => 'too_large',
            'error' => 'Image is too large (max ' . (int)round(MAX_UPLOAD_SIZE / 1048576) . ' MB).',
            'hint' => 'Use a smaller photo or the camera capture.',
        ];
    }
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $raw = @file_get_contents($tmpPath);
    if ($raw === false || $raw === '') {
        return [
            'ok' => false,
            'code' => 'read_failed',
            'error' => 'Could not read the uploaded image.',
            'hint' => 'Try again or use camera capture.',
        ];
    }
    $head = substr($raw, 0, 32);
    if (client_face_is_heic($ext, $mime, $head)) {
        return [
            'ok' => false,
            'code' => 'heic_unsupported',
            'error' => 'iPhone HEIC photos are not supported on the server.',
            'hint' => 'In iPhone Settings → Camera → Formats → Most Compatible, or use Always Scan / camera capture (JPEG).',
        ];
    }
    if ($ext !== '' && !in_array($ext, client_face_allowed_extensions(), true)
        && !str_starts_with(strtolower($mime), 'image/')) {
        return [
            'ok' => false,
            'code' => 'unsupported_type',
            'error' => 'Unsupported file type.' . ($ext !== '' ? " (.$ext)" : ''),
            'hint' => 'Use JPG, PNG, WEBP, GIF, BMP, or the camera.',
        ];
    }

    $gd = client_face_gd_from_bytes($raw, $ext);
    if ($gd === null) {
        return [
            'ok' => false,
            'code' => 'decode_failed',
            'error' => 'Could not decode this image format.',
            'hint' => 'Convert to JPG/PNG, or capture with the Face ID camera.',
        ];
    }

    $w = imagesx($gd);
    $h = imagesy($gd);
    if ($w < 40 || $h < 40) {
        return [
            'ok' => false,
            'code' => 'too_small',
            'error' => 'Image is too small for Face ID.',
            'hint' => 'Use a clearer, closer face photo.',
        ];
    }

    // Downscale huge phone photos before save
    $maxEdge = 1600;
    $scale = min(1.0, $maxEdge / max($w, $h));
    if ($scale < 1.0) {
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($dst !== false) {
            imagecopyresampled($dst, $gd, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $gd = $dst;
        }
    }

    $out = tempnam(sys_get_temp_dir(), 'facejpg');
    if ($out === false || !imagejpeg($gd, $out, 88)) {
        return [
            'ok' => false,
            'code' => 'encode_failed',
            'error' => 'Could not convert image to JPEG.',
            'hint' => 'Try another file or use the camera.',
        ];
    }
    return [
        'ok' => true,
        'path' => $out,
        'mime' => 'image/jpeg',
        'bytes' => (int)@filesize($out),
    ];
}

function client_face_make_thumb_data_url(string $localPath, int $maxEdge = 320, int $quality = 82): ?string {
    if ($localPath === '' || !is_file($localPath)) {
        return null;
    }
    $raw = @file_get_contents($localPath);
    if ($raw === false || $raw === '') {
        return null;
    }
    if (strlen($raw) > 8_000_000) {
        return null;
    }
    $src = client_face_gd_from_bytes($raw, strtolower(pathinfo($localPath, PATHINFO_EXTENSION)));
    if ($src === null) {
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
 * Optional client-provided data-URL thumb (already JPEG).
 */
function client_face_parse_thumb_data_url(mixed $raw): ?string {
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    if (!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,#i', $raw)) {
        return null;
    }
    if (strlen($raw) > 400_000) {
        return null;
    }
    return $raw;
}

/**
 * Enroll Face ID for a client. Returns API payload array.
 *
 * @param array<string, mixed> $file $_FILES['face_file']
 * @return array<string, mixed>
 */
function client_face_enroll(PDO $pdo, int $clientId, array $file, ?array $descriptor, bool $consent, ?string $clientThumb = null): array {
    if (!client_face_columns_exist($pdo)) {
        client_face_fail('not_migrated', 'Face ID is not set up on this database.', 'Apply migration 017_client_face.sql.', 503);
    }
    if (!$consent) {
        client_face_fail('consent_required', 'Client consent is required before saving a Face ID photo.', 'Check the consent box and try again.');
    }
    if ($clientId <= 0) {
        client_face_fail('no_client', 'Open a client before saving a Face ID photo.');
    }
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        $map = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server size limit.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form size limit.',
            UPLOAD_ERR_PARTIAL => 'Upload was interrupted.',
            UPLOAD_ERR_NO_FILE => 'No photo provided.',
        ];
        client_face_fail('upload_error', $map[$err] ?? ('Upload error #' . $err), 'Try camera capture or a smaller JPG.');
    }

    if ($descriptor === null) {
        client_face_fail(
            'no_descriptor',
            'No face data in upload.',
            'Use a clear front-facing photo. HEIC/Live Photos often fail — switch iPhone to Most Compatible or use the camera button.'
        );
    }

    $norm = client_face_normalize_upload(
        (string)($file['tmp_name'] ?? ''),
        (string)($file['name'] ?? 'face.jpg'),
        (string)($file['type'] ?? '')
    );
    if (!$norm['ok']) {
        client_face_fail($norm['code'], $norm['error'], $norm['hint']);
    }

    $jpegPath = $norm['path'];
    $thumb = client_face_make_thumb_data_url($jpegPath);
    if ($thumb === null) {
        $thumb = client_face_parse_thumb_data_url($clientThumb);
    }

    $upload = [
        'name' => 'face.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => $jpegPath,
        'error' => UPLOAD_ERR_OK,
        'size' => (int)$norm['bytes'],
    ];
    $path = upload_file($upload, 'client-faces', false);
    if ($path === false) {
        $path = null;
    }

    // Last resort: if storage failed, keep JPEG bytes as thumb only
    if (($path === null || $path === '') && $thumb === null) {
        $bytes = @file_get_contents($jpegPath);
        if (is_string($bytes) && $bytes !== '' && strlen($bytes) < 220_000) {
            $thumb = 'data:image/jpeg;base64,' . base64_encode($bytes);
        }
    }
    @unlink($jpegPath);

    if (($path === null || $path === '') && $thumb === null) {
        client_face_fail(
            'persist_failed',
            'Could not store the Face ID image.',
            'Check Supabase storage credentials, then try again with a JPG from the camera.'
        );
    }

    $descJson = client_face_encode_descriptor($descriptor);
    if (client_face_thumb_column_exists($pdo)) {
        $pdo->prepare(
            'UPDATE clients
             SET face_photo_path = ?,
                 face_photo_thumb = COALESCE(?, face_photo_thumb),
                 face_descriptor = ?,
                 face_consent_at = ' . sql_now() . ',
                 face_enrolled_at = ' . sql_now() . '
             WHERE id = ?'
        )->execute([$path, $thumb, $descJson, $clientId]);
    } else {
        if ($path === null || $path === '') {
            client_face_fail('not_migrated', 'Thumb column missing.', 'Apply 019_client_face_thumb.sql.', 503);
        }
        $pdo->prepare(
            'UPDATE clients
             SET face_photo_path = ?,
                 face_descriptor = ?,
                 face_consent_at = ' . sql_now() . ',
                 face_enrolled_at = ' . sql_now() . '
             WHERE id = ?'
        )->execute([$path, $descJson, $clientId]);
    }

    $display = $thumb ?: ($path ? stored_file_url($path) : '');
    return [
        'success' => true,
        'path' => $path,
        'path_url' => $path ? stored_file_url($path) : '',
        'face_photo_display_url' => $display,
        'face_enrolled' => true,
        'has_descriptor' => true,
        'stored_remote' => is_string($path) && str_starts_with($path, 'storage://'),
        'code' => 'ok',
    ];
}

function client_face_with_display_urls(array $client): array {
    $client = with_stored_file_urls($client, ['sender_id_path', 'income_doc_path', 'face_photo_path']);
    $thumb = trim((string)($client['face_photo_thumb'] ?? ''));
    if ($thumb !== '' && str_starts_with($thumb, 'data:image/')) {
        // Durable: thumb lives in DB (survives redeploys / multi-instance)
        $client['face_photo_display_url'] = $thumb;
    } elseif (!empty($client['face_photo_path_url'])) {
        $path = (string)($client['face_photo_path'] ?? '');
        // Only remote storage is reliable on Render; skip ephemeral local paths (404 noise)
        if (str_starts_with($path, 'storage://')) {
            $client['face_photo_display_url'] = $client['face_photo_path_url'];
        } else {
            $client['face_photo_display_url'] = '';
        }
    } else {
        $client['face_photo_display_url'] = '';
    }
    unset($client['face_photo_thumb']);
    return $client;
}
