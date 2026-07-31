<?php
/**
 * Cross-store check shipments + negative company balance calculator for Caja.
 */

function caja_balance_latest_rows(PDO $pdo, ?int $storeId = null): array {
    $where = '';
    $params = [];
    if ($storeId) {
        $where = 'AND br.store_id = ?';
        $params[] = $storeId;
    }

    $sql = "SELECT
                br.store_id,
                s.name AS store_name,
                COALESCE(br.company, 'Barri') AS company,
                br.ending_balance,
                br.beginning_balance,
                br.report_date_from,
                br.report_date_to,
                br.id AS report_id
            FROM barri_reports br
            JOIN stores s ON s.id = br.store_id
            INNER JOIN (
                SELECT store_id, COALESCE(company, 'Barri') AS company, MAX(report_date_to) AS max_date
                FROM barri_reports
                GROUP BY store_id, COALESCE(company, 'Barri')
            ) latest ON br.store_id = latest.store_id
                    AND COALESCE(br.company, 'Barri') = latest.company
                    AND br.report_date_to = latest.max_date
            WHERE " . sql_is_active('s.active') . " {$where}
            ORDER BY s.name, br.company";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

/** Sum of check amounts shipped TO a store for a company (status sent/applied). */
function caja_balance_shipped_toward(PDO $pdo, int $toStoreId, string $company): float {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(check_amount),0)
         FROM caja_check_shipments
         WHERE to_store_id = ?
           AND LOWER(company) = LOWER(?)
           AND status IN ('sent','applied','pending')"
    );
    $stmt->execute([$toStoreId, $company]);
    return round((float)$stmt->fetchColumn(), 2);
}

/**
 * Negative balance calculator across stores/companies.
 * projected = ending_balance + checks_shipped_to_this_store (helps clear negatives).
 */
function caja_balance_calculator(PDO $pdo, ?int $focusStoreId = null): array {
    $rows = caja_balance_latest_rows($pdo, null);
    $negatives = [];
    $byStore = [];
    $totalNegative = 0.0;
    $totalShipped = 0.0;
    $totalRemaining = 0.0;

    foreach ($rows as $r) {
        $sid = (int)$r['store_id'];
        $company = (string)$r['company'];
        $ending = round((float)$r['ending_balance'], 2);
        $shipped = caja_balance_shipped_toward($pdo, $sid, $company);
        $projected = round($ending + $shipped, 2);
        $remaining = $ending < 0 ? round(max(0, -$ending - $shipped), 2) : 0.0;

        $item = [
            'store_id' => $sid,
            'store_name' => $r['store_name'],
            'company' => $company,
            'ending_balance' => $ending,
            'checks_shipped_in' => $shipped,
            'projected_balance' => $projected,
            'remaining_to_clear' => $remaining,
            'is_negative' => $ending < 0,
            'cleared' => $ending < 0 && $remaining <= 0.009,
            'report_date_from' => $r['report_date_from'],
            'report_date_to' => $r['report_date_to'],
            'report_id' => (int)$r['report_id'],
        ];

        if (!isset($byStore[$sid])) {
            $byStore[$sid] = [
                'store_id' => $sid,
                'store_name' => $r['store_name'],
                'companies' => [],
                'negative_total' => 0.0,
                'remaining_total' => 0.0,
            ];
        }
        $byStore[$sid]['companies'][] = $item;
        if ($ending < 0) {
            $byStore[$sid]['negative_total'] += $ending;
            $byStore[$sid]['remaining_total'] += $remaining;
            $negatives[] = $item;
            $totalNegative += $ending;
            $totalShipped += $shipped;
            $totalRemaining += $remaining;
        }
    }

    // Sort negatives by most negative first
    usort($negatives, static fn($a, $b) => $a['ending_balance'] <=> $b['ending_balance']);

    $focus = null;
    if ($focusStoreId) {
        $focus = $byStore[$focusStoreId] ?? null;
    }

    return [
        'negatives' => $negatives,
        'stores' => array_values($byStore),
        'focus_store' => $focus,
        'summary' => [
            'negative_accounts' => count($negatives),
            'total_negative' => round($totalNegative, 2),
            'total_checks_shipped' => round($totalShipped, 2),
            'total_remaining_to_clear' => round($totalRemaining, 2),
        ],
        'rule' => 'Send checks cashed at Store A to Store B when Store B has a negative company balance (Barri/Viamericas/…). Projected = report ending balance + checks already shipped to that store/company.',
    ];
}

function caja_shipment_present(array $row): array {
    $paths = [];
    if (!empty($row['image_paths_json'])) {
        $decoded = json_decode((string)$row['image_paths_json'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $p) {
                $p = trim((string)$p);
                if ($p !== '') {
                    $paths[] = $p;
                }
            }
        }
    }
    $primary = trim((string)($row['image_path'] ?? ''));
    if ($primary !== '' && !in_array($primary, $paths, true)) {
        array_unshift($paths, $primary);
    }
    $row['images'] = array_map(static function (string $p): array {
        return [
            'path' => $p,
            'url' => function_exists('stored_file_url') ? stored_file_url($p) : ('/' . ltrim($p, '/')),
        ];
    }, $paths);
    $row['image_url'] = $row['images'][0]['url'] ?? '';
    return $row;
}

function caja_list_shipments(PDO $pdo, ?int $fromStoreId = null, ?int $toStoreId = null, ?string $company = null, int $limit = 100): array {
    $where = ['1=1'];
    $params = [];
    if ($fromStoreId) {
        $where[] = 'cs.from_store_id = ?';
        $params[] = $fromStoreId;
    }
    if ($toStoreId) {
        $where[] = 'cs.to_store_id = ?';
        $params[] = $toStoreId;
    }
    if ($company) {
        $where[] = 'LOWER(cs.company) = LOWER(?)';
        $params[] = $company;
    }
    $sql = 'SELECT cs.*, fs.name AS from_store_name, ts.name AS to_store_name, u.name AS created_by_name
        FROM caja_check_shipments cs
        LEFT JOIN stores fs ON fs.id = cs.from_store_id
        LEFT JOIN stores ts ON ts.id = cs.to_store_id
        LEFT JOIN users u ON u.id = cs.created_by_user_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY cs.shipment_date DESC, cs.id DESC
        LIMIT ' . (int)$limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map('caja_shipment_present', $stmt->fetchAll() ?: []);
}
