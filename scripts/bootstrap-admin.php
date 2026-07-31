<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$email = trim((string)getenv('ADMIN_BOOTSTRAP_EMAIL'));
$password = (string)getenv('ADMIN_BOOTSTRAP_PASSWORD');
$name = trim((string)(getenv('ADMIN_BOOTSTRAP_NAME') ?: 'Administrador El Rey'));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "ADMIN_BOOTSTRAP_EMAIL must be a valid deployment-specific email.\n");
    exit(2);
}

$strongPassword = strlen($password) >= 14
    && preg_match('/[a-z]/', $password)
    && preg_match('/[A-Z]/', $password)
    && preg_match('/[0-9]/', $password)
    && preg_match('/[^a-zA-Z0-9]/', $password);
if (!$strongPassword) {
    fwrite(STDERR, "ADMIN_BOOTSTRAP_PASSWORD must be at least 14 characters and include upper, lower, number, and symbol characters.\n");
    exit(2);
}

if ($name === '' || strlen($name) > 100) {
    fwrite(STDERR, "ADMIN_BOOTSTRAP_NAME must contain 1-100 characters.\n");
    exit(2);
}

$pdo = db();
$existingAdmin = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
if ((int)$existingAdmin > 0) {
    fwrite(STDOUT, "Administrator bootstrap skipped: an administrator already exists.\n");
    exit(0);
}

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
if ($hash === false) {
    fwrite(STDERR, "Unable to hash administrator password.\n");
    exit(1);
}

$stmt = $pdo->prepare(
    'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)'
);
$stmt->execute([$name, strtolower($email), $hash, 'admin']);

fwrite(STDOUT, "Administrator created. Remove ADMIN_BOOTSTRAP_PASSWORD from the runtime environment now.\n");
