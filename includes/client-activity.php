<?php

/**
 * Client activity log helpers (audit trail for client-level events).
 */

require_once __DIR__ . '/sql.php';

function client_activity_table_exists(PDO $pdo): bool
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'pgsql') {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = 'public' AND table_name = 'client_activity_log'"
        );
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'client_activity_log'"
    );
    $stmt->execute();
    return (bool)$stmt->fetchColumn();
}

/**
 * Log a client activity event. No-op when the table has not been migrated yet.
 */
function client_activity_log(
    PDO $pdo,
    int $clientId,
    string $eventType,
    string $summary,
    ?int $actorUserId = null,
    ?int $storeId = null,
    ?array $payload = null
): void {
    if (!client_activity_table_exists($pdo)) {
        return;
    }

    $payloadJson = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;

    $stmt = $pdo->prepare(
        'INSERT INTO client_activity_log (client_id, store_id, actor_user_id, event_type, summary, payload)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$clientId, $storeId, $actorUserId, $eventType, $summary, $payloadJson]);
}

/**
 * Fetch recent activity for a client, newest first, with actor name from users table.
 */
function client_activity_list(PDO $pdo, int $clientId, int $limit = 50): array
{
    if (!client_activity_table_exists($pdo)) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT cal.*, u.name AS actor_name
         FROM client_activity_log cal
         LEFT JOIN users u ON u.id = cal.actor_user_id
         WHERE cal.client_id = ?
         ORDER BY cal.created_at DESC
         LIMIT ?"
    );
    $stmt->execute([$clientId, $limit]);
    return $stmt->fetchAll() ?: [];
}
