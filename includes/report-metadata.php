<?php

function barri_normalize_agency_number(string $value): string {
    return strtoupper(trim(str_replace(' ', '', $value)));
}

function barri_agency_match_values(string $agency): array {
    $agency = barri_normalize_agency_number($agency);
    if ($agency === '') {
        return [];
    }
    $values = [$agency];
    if (preg_match('/^A(\d{4,8})$/', $agency, $m)) {
        $values[] = $m[1];
    } elseif (preg_match('/^\d{4,8}$/', $agency)) {
        $values[] = 'A' . $agency;
    }
    return array_values(array_unique($values));
}

function barri_parsed_operator_number(array $data): string {
    $op = barri_normalize_agency_number((string)($data['operator_number'] ?? ''));
    if ($op !== '') {
        return $op;
    }
    $txns = $data['transactions'] ?? [];
    if (!is_array($txns)) {
        return '';
    }
    foreach ($txns as $row) {
        if (!is_array($row)) {
            continue;
        }
        $candidate = barri_normalize_agency_number((string)($row['operator'] ?? ''));
        if ($candidate !== '' && preg_match('/^(SULY|A)\d+$/i', $candidate)) {
            return $candidate;
        }
    }
    return '';
}

function barri_auto_match_store(PDO $pdo, array $data): ?int {
    $parsedAgency = barri_normalize_agency_number((string)($data['agency_number'] ?? ''));
    $agencyValues = barri_agency_match_values($parsedAgency);
    if ($agencyValues) {
        $placeholders = implode(',', array_fill(0, count($agencyValues), '?'));
        $params = array_merge($agencyValues, $agencyValues, $agencyValues, $agencyValues);
        $storeMatch = $pdo->prepare(
            'SELECT id FROM stores WHERE (
                barri_agency_number IN (' . $placeholders . ')
                OR viamericas_agency_number IN (' . $placeholders . ')
                OR intercambio_agency_number IN (' . $placeholders . ')
                OR intermex_agency_number IN (' . $placeholders . ')
            ) AND ' . sql_is_active() . ' LIMIT 1'
        );
        $storeMatch->execute($params);
        $autoStore = $storeMatch->fetch();
        if ($autoStore) {
            return (int)$autoStore['id'];
        }
    }

    $parsedOperator = barri_parsed_operator_number($data);
    if ($parsedOperator !== '') {
        $storeMatch = $pdo->prepare('SELECT id FROM stores WHERE barri_operator_number = ? AND ' . sql_is_active() . ' LIMIT 1');
        $storeMatch->execute([$parsedOperator]);
        $autoStore = $storeMatch->fetch();
        if ($autoStore) {
            return (int)$autoStore['id'];
        }
    }

    $reportAddr = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', trim($data['agency_address'] ?? '')));
    $reportName = strtolower(trim($data['agency_name'] ?? ''));
    $reportStoreName = strtolower(trim($data['store_name'] ?? ''));

    if (!$reportAddr && !$reportName && !$reportStoreName) {
        return null;
    }

    $allStores = $pdo->query('SELECT id, name, address FROM stores WHERE ' . sql_is_active())->fetchAll();
    $bestId = null;
    $bestScore = 0;

    foreach ($allStores as $s) {
        $score = 0;
        $sAddr = strtolower(preg_replace('/[^a-z0-9]+/', ' ', trim($s['address'] ?? '')));
        $sName = strtolower(trim($s['name'] ?? ''));

        if ($reportAddr && $sAddr && strlen($reportAddr) > 5 && strlen($sAddr) > 5) {
            $rTokens = array_filter(explode(' ', $reportAddr), fn($t) => strlen($t) > 1);
            $hits = 0;
            foreach ($rTokens as $tok) {
                if (str_contains($sAddr, $tok)) $hits++;
            }
            if (count($rTokens) > 0) {
                $addrScore = $hits / count($rTokens);
                if ($addrScore >= 0.5) $score = max($score, $addrScore * 10);
            }
        }

        $namesToCheck = array_filter([$reportName, $reportStoreName]);
        foreach ($namesToCheck as $rn) {
            if (!$rn || !$sName) continue;
            if (str_contains($rn, $sName) || str_contains($sName, $rn)) {
                $score = max($score, 8);
            } else {
                $sWords = array_filter(explode(' ', $sName), fn($w) => strlen($w) > 2);
                $matched = 0;
                foreach ($sWords as $w) { if (str_contains($rn, $w)) $matched++; }
                if (count($sWords) > 0 && $matched / count($sWords) >= 0.5) {
                    $score = max($score, 5 * ($matched / count($sWords)));
                }
            }
        }

        if ($score > $bestScore) { $bestScore = $score; $bestId = (int)$s['id']; }
    }

    return ($bestId && $bestScore >= 3) ? $bestId : null;
}
