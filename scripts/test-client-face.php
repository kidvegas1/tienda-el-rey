<?php
/**
 * Unit checks for client face descriptor helpers.
 * Run: php scripts/test-client-face.php
 */
declare(strict_types=1);

require __DIR__ . '/../includes/client-face.php';

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

$good = array_fill(0, 128, 0.1);
$good[0] = 1.0;
$parsed = client_face_parse_descriptor(json_encode($good));
assert_true(is_array($parsed) && count($parsed) === 128, 'parse 128-float descriptor');
assert_true(client_face_parse_descriptor('[1,2,3]') === null, 'reject short descriptor');
assert_true(client_face_parse_descriptor('not-json') === null, 'reject junk');
assert_true(client_face_parse_descriptor($good) !== null, 'accept array input');
$encoded = client_face_encode_descriptor($parsed);
assert_true(is_string($encoded) && str_starts_with($encoded, '['), 'encode descriptor json');

if (function_exists('imagecreatetruecolor')) {
    $tmp = tempnam(sys_get_temp_dir(), 'face');
    $im = imagecreatetruecolor(64, 64);
    imagejpeg($im, $tmp, 85);
    $thumb = client_face_make_thumb_data_url($tmp);
    assert_true(is_string($thumb) && str_starts_with($thumb, 'data:image/jpeg;base64,'), 'make face thumb data url');
    @unlink($tmp);
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed\n");
    exit(1);
}

echo "All client-face unit tests passed.\n";

// Optional DB check when env is configured
if (!is_file(__DIR__ . '/../config.php')) {
    exit(0);
}
try {
    require __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/helpers.php';
    require_once __DIR__ . '/../includes/sql.php';
    $pdo = db();
    $exists = client_face_columns_exist($pdo);
    echo ($exists ? 'OK' : 'SKIP') . ": clients.face_descriptor column\n";
    if ($exists) {
        $n = (int)$pdo->query(
            "SELECT COUNT(*) FROM clients WHERE face_descriptor IS NOT NULL AND TRIM(face_descriptor) <> ''"
        )->fetchColumn();
        echo "OK: enrolled faces count={$n}\n";
    }
} catch (Throwable $e) {
    echo 'SKIP: DB — ' . $e->getMessage() . "\n";
}
