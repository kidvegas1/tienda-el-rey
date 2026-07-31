<?php
require_once __DIR__ . '/../includes/commission.php';

$failures = 0;
$assertSame = static function ($actual, $expected, string $message) use (&$failures): void {
    if ($actual !== $expected) {
        echo "FAIL: {$message}; got " . var_export($actual, true)
            . ', expected ' . var_export($expected, true) . "\n";
        $failures++;
    }
};

// Every check receives one rate on its full amount. This explicitly guards
// against the old gross-volume / marginal-band calculation.
$amounts = [100.00, 2000.00, 2000.01, 4000.00, 4000.01, 7000.00, 7000.01];
$result = commission_calculate_checks($amounts);

$expectedCommission = round(
    100.00 * 0.01
    + 2000.00 * 0.01
    + 2000.01 * 0.02
    + 4000.00 * 0.02
    + 4000.01 * 0.03
    + 7000.00 * 0.03
    + 7000.01 * 0.04,
    2
);

$assertSame($result['commission'], $expectedCommission, 'commission is calculated per full check');
$assertSame($result['check_count'], 7, 'all checks counted');
$assertSame($result['breakdown'][0]['check_count'], 2, '1% check count');
$assertSame($result['breakdown'][1]['check_count'], 2, '2% check count');
$assertSame($result['breakdown'][2]['check_count'], 2, '3% check count');
$assertSame($result['breakdown'][3]['check_count'], 1, '4% check count');
$assertSame($result['calculation_method'], 'per_check', 'calculation method');
$assertSame(commission_is_check_cashing_type('cambio_cheque'), true, 'RIA cambio type included');
$assertSame(commission_is_check_cashing_type('cambio_cheques'), true, 'plural cambio type included');
$assertSame(commission_is_check_cashing_type('Cambio de Cheques'), true, 'human-readable cambio type included');
$assertSame(commission_is_check_cashing_type('cheque_escaneado'), true, 'Intercambio scanned check included');
$assertSame(commission_is_check_cashing_type('money_transfer'), false, 'money transfers excluded');
$assertSame(commission_is_check_cashing_type('giros'), false, 'giros excluded');
$assertSame(commission_is_check_cashing_type('money_order'), false, 'money orders excluded');

echo $failures === 0
    ? "OK: per-check commission calculation passed\n"
    : "FAILED: {$failures} assertion(s)\n";
exit($failures === 0 ? 0 : 1);
