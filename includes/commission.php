<?php
/**
 * Store commission tracker for Libro interno.
 *
 * Progressive (marginal) bands — every $100 at 1% earns $1:
 *   $0 – $2,000     → 1%
 *   $2,000 – $4,000 → 2%
 *   $4,000 – $7,000 → 3%
 *   $7,000+         → 4%
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

/**
 * @return array{
 *   volume: float,
 *   commission: float,
 *   current_rate: float,
 *   current_rate_pct: float,
 *   current_tier_label: string,
 *   next_tier: ?array,
 *   progress_pct: float,
 *   overall_progress_pct: float,
 *   per_100: float,
 *   breakdown: list<array>,
 *   tiers: list<array>,
 *   rule_summary: string
 * }
 */
function commission_calculate(float $volume): array {
    $volume = max(0.0, round($volume, 2));
    $tiers = commission_tiers();
    $breakdown = [];
    $commission = 0.0;
    $currentIdx = 0;

    foreach ($tiers as $i => $tier) {
        $min = (float)$tier['min'];
        $max = $tier['max'] === null ? null : (float)$tier['max'];
        $rate = (float)$tier['rate'];

        if ($volume <= $min) {
            $inBand = 0.0;
        } elseif ($max === null) {
            $inBand = round($volume - $min, 2);
        } else {
            $inBand = round(min($volume, $max) - $min, 2);
        }
        $inBand = max(0.0, $inBand);
        $earned = commission_on_amount($inBand, $rate);
        $commission += $earned;

        // Current tier: first band that still contains volume (at boundary stay in that tier)
        $isCurrent = false;
        if ($max === null) {
            $isCurrent = $volume >= $min;
        } else {
            $isCurrent = $volume >= $min && $volume <= $max;
            // If volume exactly on a boundary shared with next tier, prefer the lower tier
            if ($isCurrent && $volume === $max && isset($tiers[$i + 1])) {
                $isCurrent = true; // 2000 counts as end of 1% band
            }
        }
        // Override: volume past this max means not current
        if ($max !== null && $volume > $max) {
            $isCurrent = false;
        }
        if ($isCurrent) {
            $currentIdx = $i;
        }

        $breakdown[] = [
            'min' => $min,
            'max' => $max,
            'rate' => $rate,
            'rate_pct' => round($rate * 100, 2),
            'label' => (string)$tier['label'],
            'volume_in_tier' => $inBand,
            'commission' => $earned,
            'active' => $inBand > 0,
            'is_current' => false,
        ];
    }

    if ($volume > 7000) {
        $currentIdx = count($tiers) - 1;
    } elseif ($volume <= 0) {
        $currentIdx = 0;
    } else {
        foreach ($tiers as $i => $tier) {
            $min = (float)$tier['min'];
            $max = $tier['max'] === null ? null : (float)$tier['max'];
            if ($max === null) {
                if ($volume > $min) {
                    $currentIdx = $i;
                }
            } elseif ($volume > $min && $volume <= $max) {
                $currentIdx = $i;
            } elseif ($volume === $min && $i === 0) {
                $currentIdx = 0;
            }
        }
        // Exactly 0 → tier 0; exactly 2000 → still tier 0; 2000.01 → tier 1
        if ($volume > 0 && $volume <= 2000) {
            $currentIdx = 0;
        } elseif ($volume > 2000 && $volume <= 4000) {
            $currentIdx = 1;
        } elseif ($volume > 4000 && $volume <= 7000) {
            $currentIdx = 2;
        } elseif ($volume > 7000) {
            $currentIdx = 3;
        }
    }

    foreach ($breakdown as $i => &$band) {
        $band['is_current'] = ($i === $currentIdx);
    }
    unset($band);

    $current = $tiers[$currentIdx];
    $currentRate = (float)$current['rate'];
    $currentLabel = (string)$current['label'];

    $nextTier = null;
    $progressPct = 100.0;
    if ($current['max'] !== null) {
        $min = (float)$current['min'];
        $max = (float)$current['max'];
        $span = $max - $min;
        $into = max(0.0, min($volume, $max) - $min);
        $progressPct = $span > 0 ? min(100.0, round(($into / $span) * 100, 1)) : 0.0;
        $next = $tiers[$currentIdx + 1] ?? null;
        if ($next) {
            $nextTier = [
                'threshold' => $max,
                'remaining' => round(max(0.0, $max - $volume), 2),
                'rate' => (float)$next['rate'],
                'rate_pct' => round((float)$next['rate'] * 100, 2),
                'label' => (string)$next['label'],
            ];
        }
    }

    $topMarker = 7000.0;
    $overallPct = min(100.0, round(($volume / $topMarker) * 100, 1));

    return [
        'volume' => $volume,
        'commission' => round($commission, 2),
        'current_rate' => $currentRate,
        'current_rate_pct' => round($currentRate * 100, 2),
        'current_tier_label' => $currentLabel,
        'next_tier' => $nextTier,
        'progress_pct' => $progressPct,
        'overall_progress_pct' => $overallPct,
        'per_100' => round($currentRate * 100, 2),
        'breakdown' => $breakdown,
        'tiers' => array_map(static function (array $t): array {
            return [
                'min' => $t['min'],
                'max' => $t['max'],
                'rate' => $t['rate'],
                'rate_pct' => round($t['rate'] * 100, 2),
                'label' => $t['label'],
            ];
        }, $tiers),
        'rule_summary' => '1% up to $2,000 · 2% past $2,000 · 3% past $4,000 · 4% past $7,000 ($1 per $100 at 1%)',
    ];
}

/**
 * Volume = max(ledger owed_to_store, imported report principal) for the period.
 * Using max avoids double-counting when the same activity is logged and imported.
 */
function commission_volume_for_period(PDO $pdo, ?int $storeId, string $dateFrom, string $dateTo, ?string $employeeName = null): array {
    $storeSql = store_filter_sql('store_id', $storeId);
    $params = [$dateFrom, $dateTo];
    if ($storeId) {
        $params[] = $storeId;
    }

    $ledgerSql = "SELECT COALESCE(SUM(owed_to_store),0) AS volume, COUNT(*) AS entry_count
        FROM internal_ledger
        WHERE entry_date >= ? AND entry_date <= ?" . $storeSql;
    if ($employeeName) {
        $ledgerSql .= ' AND employee_name = ?';
        $params[] = $employeeName;
    }
    $stmt = $pdo->prepare($ledgerSql);
    $stmt->execute($params);
    $ledger = $stmt->fetch() ?: ['volume' => 0, 'entry_count' => 0];
    $ledgerVolume = round((float)($ledger['volume'] ?? 0), 2);

    $barriVolume = 0.0;
    $barriCount = 0;
    try {
        $barriParams = [$dateFrom, $dateTo];
        $barriSql = "SELECT COALESCE(SUM(principal),0) AS volume, COUNT(*) AS txn_count
            FROM barri_transactions
            WHERE transaction_date >= ? AND transaction_date <= ?" . store_filter_sql('store_id', $storeId);
        if ($storeId) {
            $barriParams[] = $storeId;
        }
        $bstmt = $pdo->prepare($barriSql);
        $bstmt->execute($barriParams);
        $barri = $bstmt->fetch() ?: ['volume' => 0, 'txn_count' => 0];
        $barriVolume = round((float)($barri['volume'] ?? 0), 2);
        $barriCount = (int)($barri['txn_count'] ?? 0);
    } catch (Throwable $e) {
        // ignore missing table
    }

    $volume = max($ledgerVolume, $barriVolume);
    $source = 'none';
    if ($ledgerVolume > 0 && $barriVolume > 0) {
        $source = abs($ledgerVolume - $barriVolume) < 0.01
            ? 'both'
            : ($ledgerVolume >= $barriVolume ? 'ledger' : 'reports');
    } elseif ($ledgerVolume > 0) {
        $source = 'ledger';
    } elseif ($barriVolume > 0) {
        $source = 'reports';
    }

    return [
        'volume' => $volume,
        'ledger_volume' => $ledgerVolume,
        'ledger_entries' => (int)($ledger['entry_count'] ?? 0),
        'report_volume' => $barriVolume,
        'report_transactions' => $barriCount,
        'source' => $source,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ];
}

function commission_tracker_payload(PDO $pdo, ?int $storeId, ?string $dateFrom = null, ?string $dateTo = null, ?string $employeeName = null): array {
    $now = new DateTime('now');
    $from = $dateFrom ?: $now->format('Y-m-01');
    $to = $dateTo ?: $now->format('Y-m-d');
    $vol = commission_volume_for_period($pdo, $storeId, $from, $to, $employeeName);
    $calc = commission_calculate((float)$vol['volume']);
    return array_merge($calc, $vol);
}
