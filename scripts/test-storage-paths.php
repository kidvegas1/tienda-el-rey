<?php
require_once __DIR__ . '/../includes/storage.php';

$failures = 0;
$assertTrue = static function (bool $actual, string $message) use (&$failures): void {
    if (!$actual) {
        echo "FAIL: {$message}\n";
        $failures++;
    }
};

$assertTrue(
    storage_path_in_subdir('assets/uploads/inventory/1/photo.jpg', 'inventory'),
    'local inventory path'
);
$assertTrue(
    storage_path_in_subdir('storage://client-ids/inventory/1/photo.jpg', 'inventory'),
    'remote inventory path in client-ids bucket'
);
$assertTrue(
    storage_path_in_subdir('storage://client-ids/receipts/2026/scan.pdf', 'receipts'),
    'remote receipts path in client-ids bucket'
);
$assertTrue(
    !storage_path_in_subdir('storage://inventory/photo.jpg', 'inventory'),
    'legacy inventory bucket path rejected'
);
$tmp = tempnam(sys_get_temp_dir(), 'xlsx-mime-');
file_put_contents($tmp, "PK\x03\x04");
$assertTrue(
    storage_upload_content_type($tmp, 'cambios.xlsx')
        === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'xlsx uploads use the official spreadsheet MIME type instead of application/zip'
);
$assertTrue(
    storage_upload_content_type($tmp, 'cambios.xls') === 'application/vnd.ms-excel',
    'xls uploads use the legacy spreadsheet MIME type'
);
@unlink($tmp);

echo $failures === 0
    ? "OK: storage path ACL helpers passed\n"
    : "FAILED: {$failures} assertion(s)\n";
exit($failures === 0 ? 0 : 1);
