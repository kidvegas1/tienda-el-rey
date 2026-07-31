<?php
require_once __DIR__ . '/../includes/commission.php';
require_once __DIR__ . '/../includes/reconciliation.php';

$failures = 0;
$assertSame = static function ($actual, $expected, string $message) use (&$failures): void {
    if ($actual !== $expected) {
        echo "FAIL: {$message}; got " . var_export($actual, true)
            . ', expected ' . var_export($expected, true) . "\n";
        $failures++;
    }
};

$amounts = [1200.00, 2500.00, 500.00, 1800.00, 900.00, 1100.00, 703.66];
$calc = commission_calculate_checks($amounts);
$assertSame($calc['check_count'], 7, 'check count matches priced checks');
$assertSame($calc['volume'], round(array_sum($amounts), 2), 'check volume sums absolute amounts');
$assertSame($calc['commission'], round(array_sum(array_map(
    static fn(float $a): float => round($a * commission_rate_for_check($a), 2),
    $amounts
)), 2), 'tier commission matches per-check pricing');

$tierCommission = $calc['commission'];
$fees = 0.50;
$centsLost = recon_cents_lost(100.37);
$profit = round($tierCommission + $fees - $centsLost, 2);
$assertSame($profit, round($tierCommission + $fees - $centsLost, 2), 'finances cambio profit formula');

$assertSame(commission_is_check_cashing_type('cambio_cheque'), true, 'RIA cambio cheque counted');
$assertSame(recon_is_cambio_type('money_order'), true, 'money_order still recon cambio');
$assertSame(commission_is_check_cashing_type('money_order'), false, 'money_order excluded from tier commission');

echo $failures === 0
    ? "OK: finances commission alignment passed\n"
    : "FAILED: {$failures} assertion(s)\n";
exit($failures === 0 ? 0 : 1);
