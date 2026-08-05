<?php

function exchange_rates_table_exists(PDO $pdo): bool {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        if (db_is_pgsql()) {
            $stmt = $pdo->query(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = 'public' AND table_name = 'remittance_exchange_rates'
                 LIMIT 1"
            );
        } else {
            $stmt = $pdo->query("SHOW TABLES LIKE 'remittance_exchange_rates'");
        }
        $cached = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * @return list<array<string,mixed>>
 */
function exchange_rates_list(PDO $pdo, bool $publishedOnly = false): array {
    if (!exchange_rates_table_exists($pdo)) {
        return [];
    }
    $sql = 'SELECT id, country_code, country_name, currency_code, rate_per_usd,
                   published, sort_order, note, updated_at
            FROM remittance_exchange_rates';
    if ($publishedOnly) {
        $sql .= ' WHERE published = ' . sql_bool(true) . ' AND rate_per_usd > 0';
    }
    $sql .= ' ORDER BY sort_order ASC, country_name ASC';
    $rows = $pdo->query($sql)->fetchAll() ?: [];
    return array_map('exchange_rates_normalize_row', $rows);
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function exchange_rates_normalize_row(array $row): array {
    $code = strtoupper(trim((string)($row['country_code'] ?? '')));
    $flags = ['MX', 'GT', 'HN', 'SV'];
    return [
        'id'            => (int)($row['id'] ?? 0),
        'country_code'  => $code,
        'country_name'  => (string)($row['country_name'] ?? ''),
        'currency_code' => strtoupper(trim((string)($row['currency_code'] ?? ''))),
        'rate_per_usd'  => round((float)($row['rate_per_usd'] ?? 0), 4),
        'published'     => !empty($row['published']) && $row['published'] !== 'f' && $row['published'] !== '0',
        'sort_order'    => (int)($row['sort_order'] ?? 0),
        'note'          => (string)($row['note'] ?? ''),
        'updated_at'    => $row['updated_at'] ?? null,
        'flag_url'      => in_array($code, $flags, true)
            ? '/assets/images/flags/' . exchange_rates_flag_slug($code) . '.svg'
            : null,
    ];
}

function exchange_rates_flag_slug(string $code): string {
    return match (strtoupper($code)) {
        'MX' => 'mexico',
        'GT' => 'guatemala',
        'HN' => 'honduras',
        'SV' => 'el-salvador',
        default => strtolower($code),
    };
}

/**
 * @param list<array<string,mixed>> $items
 */
function exchange_rates_bulk_save(PDO $pdo, array $items, ?int $userId): int {
    if (!exchange_rates_table_exists($pdo)) {
        throw new RuntimeException('Exchange rates table is not migrated yet.');
    }
    $stmt = $pdo->prepare(
        'UPDATE remittance_exchange_rates
         SET rate_per_usd = ?,
             published = ?,
             note = ?,
             updated_at = ' . sql_now() . ',
             updated_by_user_id = ?
         WHERE id = ?'
    );
    $count = 0;
    foreach ($items as $item) {
        $id = (int)($item['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $rate = max(0, (float)($item['rate_per_usd'] ?? 0));
        $published = !empty($item['published']);
        $note = sanitize((string)($item['note'] ?? ''));
        if (mb_strlen($note) > 200) {
            $note = mb_substr($note, 0, 200);
        }
        $stmt->execute([$rate, db_bool($published), $note !== '' ? $note : null, $userId, $id]);
        $count += $stmt->rowCount() > 0 ? 1 : 0;
    }
    return $count;
}
