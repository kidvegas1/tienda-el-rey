<?php
/**
 * Unit checks for Face ID enrollment helpers.
 * Run: php scripts/test-client-face.php
 */
declare(strict_types=1);

$failures = 0;

function assert_true(bool $cond, string $label): void {
    global $failures;
    if ($cond) {
        echo "PASS: $label\n";
        return;
    }
    $failures++;
    echo "FAIL: $label\n";
}

// Prefer full app bootstrap when available (avoids stub redeclarations)
if (is_file(__DIR__ . '/../config.php')) {
    require __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/helpers.php';
    require_once __DIR__ . '/../includes/sql.php';
    require_once __DIR__ . '/../includes/storage.php';
} elseif (!defined('MAX_UPLOAD_SIZE')) {
    define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);
}

if (!function_exists('with_stored_file_urls')) {
    function with_stored_file_urls(array $row, array $keys = []): array {
        return $row;
    }
}

require_once __DIR__ . '/../includes/client-face.php';

$good = array_fill(0, 128, 0.1);
$good[0] = 1.0;
$parsed = client_face_parse_descriptor(json_encode($good));
assert_true(is_array($parsed) && count($parsed) === 128, 'parse 128-float descriptor');
assert_true(client_face_parse_descriptor('[1,2,3]') === null, 'reject short descriptor');
assert_true(client_face_parse_descriptor('not-json') === null, 'reject junk');
assert_true(client_face_parse_descriptor($good) !== null, 'accept array input');
$encoded = client_face_encode_descriptor($parsed);
assert_true(is_string($encoded) && str_starts_with($encoded, '['), 'encode descriptor json');

assert_true(client_face_is_heic('heic', '', '') === true, 'heic by extension');
assert_true(client_face_is_heic('jpg', 'image/heic', '') === true, 'heic by mime');
assert_true(client_face_is_heic('jpg', 'image/jpeg', '') === false, 'jpeg not heic');
assert_true(client_face_is_jpeg_bytes("\xFF\xD8\xFF\xE0test") === true, 'jpeg magic bytes');
assert_true(client_face_is_jpeg_bytes('PNG') === false, 'reject non-jpeg magic');

if (function_exists('imagecreatetruecolor')) {
    $tmpJpg = tempnam(sys_get_temp_dir(), 'facejpg');
    $im = imagecreatetruecolor(120, 120);
    $white = imagecolorallocate($im, 255, 255, 255);
    imagefilledrectangle($im, 0, 0, 119, 119, $white);
    imagejpeg($im, $tmpJpg, 85);
    $thumb = client_face_make_thumb_data_url($tmpJpg);
    assert_true(is_string($thumb) && str_starts_with($thumb, 'data:image/jpeg;base64,'), 'make face thumb data url');

    $norm = client_face_normalize_upload($tmpJpg, 'photo.jpg', 'image/jpeg');
    assert_true(($norm['ok'] ?? false) === true, 'normalize jpeg ok');
    assert_true(($norm['mime'] ?? '') === 'image/jpeg', 'normalize mime jpeg');
    if (!empty($norm['path'])) {
        @unlink($norm['path']);
    }
    @unlink($tmpJpg);

    $tmpPng = tempnam(sys_get_temp_dir(), 'facepng') . '.png';
    imagepng($im, $tmpPng);
    $normPng = client_face_normalize_upload($tmpPng, 'shot.png', 'image/png');
    assert_true(($normPng['ok'] ?? false) === true, 'normalize png → jpeg');
    if (!empty($normPng['path'])) {
        @unlink($normPng['path']);
    }
    @unlink($tmpPng);

    $tiny = tempnam(sys_get_temp_dir(), 'facetiny');
    $tinyIm = imagecreatetruecolor(20, 20);
    imagejpeg($tinyIm, $tiny, 85);
    $normTiny = client_face_normalize_upload($tiny, 'tiny.jpg', 'image/jpeg');
    assert_true(($normTiny['ok'] ?? true) === false && ($normTiny['code'] ?? '') === 'too_small', 'reject tiny image');
    @unlink($tiny);

    $heicFake = tempnam(sys_get_temp_dir(), 'faceheic');
    file_put_contents($heicFake, "\0\0\0\x18ftypheic\0\0\0\0");
    $normHeic = client_face_normalize_upload($heicFake, 'iphone.heic', 'image/heic');
    assert_true(($normHeic['ok'] ?? true) === false && ($normHeic['code'] ?? '') === 'heic_unsupported', 'reject heic');
    @unlink($heicFake);

    $thumbParsed = client_face_parse_thumb_data_url($thumb);
    assert_true(is_string($thumbParsed), 'accept client thumb data url');
    assert_true(client_face_parse_thumb_data_url('not-a-data-url') === null, 'reject bad thumb');
}

$display = client_face_with_display_urls([
    'face_photo_thumb' => 'data:image/jpeg;base64,abc',
    'face_photo_path' => 'assets/uploads/client-faces/gone.jpg',
    'face_photo_path_url' => '/api/files?ref=x',
]);
assert_true(($display['face_photo_display_url'] ?? '') === 'data:image/jpeg;base64,abc', 'prefer thumb over path');

$displayLocal = client_face_with_display_urls([
    'face_photo_path' => 'assets/uploads/client-faces/gone.jpg',
    'face_photo_path_url' => '/api/files?ref=gone',
]);
assert_true(($displayLocal['face_photo_display_url'] ?? 'x') === '', 'hide ephemeral local path without thumb');

$displayRemote = client_face_with_display_urls([
    'face_photo_path' => 'storage://bucket/client-faces/a.jpg',
]);
$remoteUrl = (string)($displayRemote['face_photo_display_url'] ?? '');
assert_true($remoteUrl !== '' && !str_starts_with($remoteUrl, 'data:'), 'allow remote storage url');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed\n");
    exit(1);
}

echo "All client-face unit tests passed.\n";

try {
    if (!function_exists('db')) {
        echo "SKIP: DB — config not loaded\n";
        exit(0);
    }
    $pdo = db();
    $exists = client_face_columns_exist($pdo);
    echo ($exists ? 'OK' : 'SKIP') . ": clients.face_descriptor column\n";
    if ($exists) {
        $n = (int)$pdo->query(
            "SELECT COUNT(*) FROM clients WHERE face_descriptor IS NOT NULL AND TRIM(face_descriptor) <> ''"
        )->fetchColumn();
        echo "OK: enrolled faces count={$n}\n";
        echo (client_face_thumb_column_exists($pdo) ? 'OK' : 'SKIP') . ": clients.face_photo_thumb column\n";
    }
} catch (Throwable $e) {
    echo 'SKIP: DB — ' . $e->getMessage() . "\n";
}
