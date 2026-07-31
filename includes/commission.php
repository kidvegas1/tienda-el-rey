<?php
/**
 * Check-cashing commission tracker for Libro interno.
 *
 * Each individual check receives one rate based on that check's amount:
 *   $0.01 – $2,000       → 1% of the full check
 *   $2,000.01 – $4,000   → 2% of the full check
 *   $4,000.01 – $7,000   → 3% of the full check
 *   Above $7,000         → 4% of the full check
 *
 * Money-transfer (envío) volume is intentionally excluded.
 */

function commission_tiers(): array {
    return [
        ['min' => 0.0,    'max' => 2000.0, 'rate' => 0.01, 'label' => '1%'],
        ['min' => 2000.0, 'max' => 4000.0, 'rate' => 0.02, 'label' => '2%'],
        ['min' => 4000.0, 'max' => 7000.0, 'rate' => 0.03, 'label' => '3%'],
        ['min' => 7000.0, 'max' => null,   'rate' => 0.04, 'label' => '4%'],
    ];
}

function commission_on_amount(float $amount, float $rate): float {
    if ($amount <= 0 || $rate <= 0) {
        return 0.0;
    }
    return round($amount * $rate, 2);
}

function commission_rate_for_check(float $amount): float {
    $amount = abs($amount);
    if ($amount <= 0) return 0.0;
    if ($amount <= 2000) return 0.01;
    if ($amount <= 4000) return 0.02;
    if ($amount <= 7000) return 0.03;
    return 0.04;
}

function commission_is_check_cashing_type(string $type): bool {
    $normalized = strtolower(trim(str_replace([' ', '-'], '_', $type)));
    if (in_array($normalized, [
        'cambio_cheque',
        'cambio_cheques',
        'cambio_de_cheques',
        'check_cashing',
        'cheque_escaneado',
    ], true)) {
        return true;
    }
    return str_contains($normalized, 'cambio') && str_contains($normalized, 'cheque');
}

/**
 * Check-cashing volume only. Money transfers and generic ledger volume are excluded.
 * Every returned amount represents one check and is priced independently.
 */
function commission_volume_for_period(PDO $pdo, ?int $storeId, string $dateFrom, string $dateTo, ?string $employeeName = null): array {
    $params = [$dateFrom, $dateTo];
    $sql = "SELECT transaction_type, ABS(principal) AS check_amount
        FROM barri_transactions
        WHERE transaction_date >= ? AND transaction_date <= ?
          AND principal <> 0";
    if ($storeId) {
        $sql .= ' AND store_id = ?';
        $params[] = $storeId;
    }
    $sql .= ' ORDER BY principal';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $amounts = array_values(array_filter(
        array_map(
            static fn(array $row): float => commission_is_check_cashing_type((string)$row['transaction_type'])
                ? round(abs((float)$row['check_amount']), 2)
                : 0.0,
            $stmt->fetchAll()
        ),
        static fn(float $amount): bool => $amount > 0
    ));

    return [
        'amounts' => $amounts,
        'volume' => round(array_sum($amounts), 2),
        'check_count' => count($amounts),
        'largest_check' => $amounts ? max($amounts) : 0.0,
        'average_check' => $amounts ? round(array_sum($amounts) / count($amounts), 2) : 0.0,
        'ledger_volume' => 0.0,
        'ledger_entries' => 0,
        'report_volume' => round(array_sum($amounts), 2),
        'report_transactions' => count($amounts),
        'source' => $amounts ? 'check_reports' : 'none',
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ];
}

function commission_calculate_checks(array $amounts): array {
    $tiers = commission_tiers();
    $breakdown = array_map(static function (array $tier): array {
        return [
            'min' => (float)$tier['min'],
            'max' => $tier['max'] === null ? null : (float)$tier['max'],
            'rate' => (float)$tier['rate'],
            'rate_pct' => round((float)$tier['rate'] * 100, 2),
            'label' => (string)$tier['label'],
            'volume_in_tier' => 0.0,
            'commission' => 0.0,
            'check_count' => 0,
            'active' => false,
            'is_current' => false,
        ];
    }, $tiers);

    $volume = 0.0;
    $commission = 0.0;
    foreach ($amounts as $rawAmount) {
        $amount = round(abs((float)$rawAmount), 2);
        if ($amount <= 0) continue;
        $rate = commission_rate_for_check($amount);
        $tierIndex = $rate === 0.01 ? 0 : ($rate === 0.02 ? 1 : ($rate === 0.03 ? 2 : 3));
        $earned = commission_on_amount($amount, $rate);
        $volume += $amount;
        $commission += $earned;
        $breakdown[$tierIndex]['volume_in_tier'] += $amount;
        $breakdown[$tierIndex]['commission'] += $earned;
        $breakdown[$tierIndex]['check_count']++;
        $breakdown[$tierIndex]['active'] = true;
    }
    foreach ($breakdown as &$band) {
        $band['volume_in_tier'] = round($band['volume_in_tier'], 2);
        $band['commission'] = round($band['commission'], 2);
    }
    unset($band);

    $volume = round($volume, 2);
    $commission = round($commission, 2);
    $effectiveRate = $volume > 0 ? $commission / $volume : 0.0;
    $checkCount = count(array_filter($amounts, static fn($a): bool => abs((float)$a) > 0));

    return [
        'volume' => $volume,
        'commission' => $commission,
        'current_rate' => $effectiveRate,
        'current_rate_pct' => round($effectiveRate * 100, 2),
        'current_tier_label' => number_format($effectiveRate * 100, 2) . '%',
        'next_tier' => null,
        'progress_pct' => 0.0,
        'overall_progress_pct' => 0.0,
        'per_100' => round($effectiveRate * 100, 2),
        'breakdown' => $breakdown,
        'tiers' => array_map(static function (array $tier): array {
            return [
                'min' => $tier['min'],
                'max' => $tier['max'],
                'rate' => $tier['rate'],
                'rate_pct' => round($tier['rate'] * 100, 2),
                'label' => $tier['label'],
            ];
        }, $tiers),
        'check_count' => $checkCount,
        'calculation_method' => 'per_check',
        'rule_summary' => 'Each check is priced independently: up to $2,000 at 1%; $2,000.01–$4,000 at 2%; $4,000.01–$7,000 at 3%; above $7,000 at 4%.',
    ];
}

function commission_tracker_payload(PDO $pdo, ?int $storeId, ?string $dateFrom = null, ?string $dateTo = null, ?string $employeeName = null): array {
    $now = new DateTime('now');
    $from = $dateFrom ?: $now->format('Y-m-01');
    $to = $dateTo ?: $now->format('Y-m-d');
    $vol = commission_volume_for_period($pdo, $storeId, $from, $to, $employeeName);
    $calc = commission_calculate_checks($vol['amounts']);
    unset($vol['amounts']);
    return array_merge($calc, $vol);
}
