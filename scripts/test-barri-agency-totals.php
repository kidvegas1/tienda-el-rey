#!/usr/bin/env php
<?php
/** Agency activity totals must use document balances, not summed principals. */

function barri_finalize_agency_activity_totals(array $data): array {
    $txns = $data['transactions'] ?? [];
    $agcomm = 0.0;
    foreach ($txns as $txn) {
        $agcomm += (float)($txn['agcomm'] ?? $txn['ag_commission'] ?? 0);
    }
    $agcomm = round($agcomm, 2);
    $begin = (float)($data['beginning_balance'] ?? 0);
    $end = (float)($data['ending_balance'] ?? 0);
    $balanceChange = round($end - $begin, 2);
    $data['total_principal'] = $balanceChange;
    $data['total_amount'] = $agcomm;
    return $data;
}

$data = [
    'beginning_balance' => 44241.96,
    'ending_balance' => -53083.57,
    'transactions' => [
        ['principal' => 1000, 'total' => 1100, 'agcomm' => 5],
        ['principal' => -500, 'total' => -490, 'agcomm' => 2.5],
    ],
];

$out = barri_finalize_agency_activity_totals($data);
$expectedChange = round(-53083.57 - 44241.96, 2);

if (abs($out['total_principal'] - $expectedChange) > 0.01) {
    fwrite(STDERR, "FAIL: total_principal {$out['total_principal']} expected {$expectedChange}\n");
    exit(1);
}
if (abs($out['total_amount'] - 7.5) > 0.01) {
    fwrite(STDERR, "FAIL: total_amount should be agcomm 7.5 got {$out['total_amount']}\n");
    exit(1);
}

echo "OK: agency activity totals use document balances\n";
