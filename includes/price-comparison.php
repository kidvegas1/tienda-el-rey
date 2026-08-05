<?php

/**
 * Supplier price comparison helpers (invoice → unit cost → cheapest provider).
 */

function price_comparison_tables_exist(PDO $pdo): bool {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        if (db_is_pgsql()) {
            $stmt = $pdo->query(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = 'public' AND table_name = 'supplier_prices' LIMIT 1"
            );
        } else {
            $stmt = $pdo->query("SHOW TABLES LIKE 'supplier_prices'");
        }
        $cached = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

function price_comparison_normalize_barcode(string $raw): string {
    $digits = preg_replace('/\D+/', '', trim($raw)) ?? '';
    if (strlen($digits) >= 8 && strlen($digits) <= 14) {
        return $digits;
    }
    return trim($raw);
}

/**
 * Match an invoice line to inventory for a store.
 * @return array{id:int,product_name:string,barcode:?string,score:string}|null
 */
function price_comparison_match_inventory(PDO $pdo, int $storeId, string $description, string $barcode = ''): ?array {
    $barcode = price_comparison_normalize_barcode($barcode);
    if ($barcode !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, product_name, barcode FROM inventory
             WHERE store_id = ? AND barcode = ? LIMIT 1'
        );
        $stmt->execute([$storeId, $barcode]);
        $row = $stmt->fetch();
        if ($row) {
            return [
                'id' => (int)$row['id'],
                'product_name' => (string)$row['product_name'],
                'barcode' => $row['barcode'] !== null ? (string)$row['barcode'] : null,
                'score' => 'barcode',
            ];
        }
        // UPC-A / EAN-13 padding
        $digits = preg_replace('/\D+/', '', $barcode) ?? '';
        if (strlen($digits) === 12) {
            $stmt->execute([$storeId, '0' . $digits]);
            $row = $stmt->fetch();
            if ($row) {
                return [
                    'id' => (int)$row['id'],
                    'product_name' => (string)$row['product_name'],
                    'barcode' => $row['barcode'] !== null ? (string)$row['barcode'] : null,
                    'score' => 'barcode',
                ];
            }
        }
        if (strlen($digits) === 13 && str_starts_with($digits, '0')) {
            $stmt->execute([$storeId, substr($digits, 1)]);
            $row = $stmt->fetch();
            if ($row) {
                return [
                    'id' => (int)$row['id'],
                    'product_name' => (string)$row['product_name'],
                    'barcode' => $row['barcode'] !== null ? (string)$row['barcode'] : null,
                    'score' => 'barcode',
                ];
            }
        }
    }

    $desc = trim($description);
    if ($desc === '' || mb_strlen($desc) < 3) {
        return null;
    }
    // ponytail: contains match; full-text search if noise gets bad
    $needle = '%' . mb_strtolower($desc) . '%';
    $stmt = $pdo->prepare(
        'SELECT id, product_name, barcode FROM inventory
         WHERE store_id = ? AND LOWER(product_name) LIKE ?
         ORDER BY LENGTH(product_name) ASC
         LIMIT 1'
    );
    $stmt->execute([$storeId, $needle]);
    $row = $stmt->fetch();
    if ($row) {
        return [
            'id' => (int)$row['id'],
            'product_name' => (string)$row['product_name'],
            'barcode' => $row['barcode'] !== null ? (string)$row['barcode'] : null,
            'score' => 'name',
        ];
    }
    // Try first 3 significant words
    $words = preg_split('/\s+/', mb_strtolower($desc)) ?: [];
    $words = array_values(array_filter($words, static fn($w) => mb_strlen($w) >= 3));
    if (count($words) >= 2) {
        $partial = '%' . implode('%', array_slice($words, 0, 3)) . '%';
        $stmt->execute([$storeId, $partial]);
        $row = $stmt->fetch();
        if ($row) {
            return [
                'id' => (int)$row['id'],
                'product_name' => (string)$row['product_name'],
                'barcode' => $row['barcode'] !== null ? (string)$row['barcode'] : null,
                'score' => 'name_partial',
            ];
        }
    }
    return null;
}

/**
 * Enrich Gemini line items with unit_cost + inventory match.
 * @param list<array<string,mixed>> $lineItems
 * @return list<array<string,mixed>>
 */
function price_comparison_enrich_lines(PDO $pdo, int $storeId, array $lineItems): array {
    $out = [];
    foreach ($lineItems as $i => $row) {
        $qty = max(0.001, (float)($row['qty'] ?? 1));
        $amount = (float)($row['amount'] ?? 0);
        $unit = isset($row['unit_cost']) && is_numeric($row['unit_cost'])
            ? round((float)$row['unit_cost'], 2)
            : round($amount / $qty, 2);
        $barcode = price_comparison_normalize_barcode((string)($row['barcode'] ?? ''));
        $desc = trim((string)($row['description'] ?? ''));
        $match = price_comparison_match_inventory($pdo, $storeId, $desc, $barcode);
        $out[] = [
            'index' => $i,
            'description' => $desc,
            'qty' => $qty,
            'amount' => round($amount, 2),
            'unit_cost' => $unit,
            'barcode' => $barcode,
            'match' => $match,
            'inventory_id' => $match['id'] ?? null,
            'skip' => false,
        ];
    }
    return $out;
}

/**
 * @return list<array<string,mixed>>
 */
function price_comparison_recommendations(PDO $pdo, ?int $storeId): array {
    $storeSql = $storeId ? ' AND i.store_id = ?' : '';
    $params = $storeId ? [$storeId] : [];
    // Latest observation per (inventory, supplier), then cheapest supplier
    $sql = "
        WITH latest AS (
            SELECT DISTINCT ON (sp.inventory_id, sp.supplier_id)
                sp.inventory_id,
                sp.supplier_id,
                sp.unit_cost,
                sp.observed_at,
                s.name AS supplier_name,
                i.product_name,
                i.barcode,
                i.store_id
            FROM supplier_prices sp
            JOIN suppliers s ON s.id = sp.supplier_id AND s.active IS TRUE
            JOIN inventory i ON i.id = sp.inventory_id
            WHERE sp.inventory_id IS NOT NULL
              AND sp.unit_cost > 0
              {$storeSql}
            ORDER BY sp.inventory_id, sp.supplier_id, sp.observed_at DESC, sp.id DESC
        ),
        ranked AS (
            SELECT *,
                   ROW_NUMBER() OVER (PARTITION BY inventory_id ORDER BY unit_cost ASC, observed_at DESC) AS rn,
                   LEAD(unit_cost) OVER (PARTITION BY inventory_id ORDER BY unit_cost ASC, observed_at DESC) AS next_cost,
                   LEAD(supplier_name) OVER (PARTITION BY inventory_id ORDER BY unit_cost ASC, observed_at DESC) AS next_supplier
            FROM latest
        )
        SELECT inventory_id, product_name, barcode, store_id,
               supplier_id, supplier_name, unit_cost, observed_at,
               next_cost, next_supplier
        FROM ranked
        WHERE rn = 1
        ORDER BY product_name
        LIMIT 500
    ";
    if (!db_is_pgsql()) {
        // ponytail: MySQL fallback — simpler aggregate
        $sql = "
            SELECT sp.inventory_id, i.product_name, i.barcode, i.store_id,
                   sp.supplier_id, s.name AS supplier_name,
                   MIN(sp.unit_cost) AS unit_cost,
                   MAX(sp.observed_at) AS observed_at,
                   NULL AS next_cost, NULL AS next_supplier
            FROM supplier_prices sp
            JOIN suppliers s ON s.id = sp.supplier_id AND s.active = 1
            JOIN inventory i ON i.id = sp.inventory_id
            WHERE sp.inventory_id IS NOT NULL AND sp.unit_cost > 0 {$storeSql}
            GROUP BY sp.inventory_id, i.product_name, i.barcode, i.store_id, sp.supplier_id, s.name
            ORDER BY i.product_name
            LIMIT 500
        ";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];
    foreach ($rows as &$r) {
        $best = (float)$r['unit_cost'];
        $next = isset($r['next_cost']) && $r['next_cost'] !== null ? (float)$r['next_cost'] : null;
        $r['inventory_id'] = (int)$r['inventory_id'];
        $r['supplier_id'] = (int)$r['supplier_id'];
        $r['unit_cost'] = $best;
        $r['savings'] = ($next !== null && $next > $best) ? round($next - $best, 2) : null;
        $r['order_from'] = (string)$r['supplier_name'];
    }
    unset($r);
    return $rows;
}
