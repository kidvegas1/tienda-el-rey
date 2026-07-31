<?php
/**
 * Self-check for company verification helpers.
 * php scripts/test-company-verification.php
 */
$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/helpers.php';
require_once $root . '/includes/company-flags.php';
require_once $root . '/includes/gemini.php';

assert(company_flag_normalize_key('  Acme   Corp ') === 'ACME CORP');

$san = gemini_sanitize_check([
    'company' => "Acme Corp\x00",
    'payer_name' => 'Acme',
    'payee_name' => 'Juan Perez',
    'amount' => '250.50',
    'check_number' => '1001',
    'check_date' => '07/15/2026',
    'bank_name' => 'Chase',
    'memo' => 'payroll',
]);
assert($san['company'] === 'Acme Corp');
assert($san['amount'] === 250.50);
assert($san['check_date'] === '2026-07-15');

$pdo = db();
$payload = company_verification_payload($pdo, 'Nonexistent Fake Co XYZ');
assert($payload['is_flagged'] === false);
assert(($payload['history']['clients_count'] ?? -1) === 0);
assert($payload['recommendation'] === 'unknown');

echo "OK company-verification self-check\n";
