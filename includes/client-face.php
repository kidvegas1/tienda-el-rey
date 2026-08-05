<?php

/**
 * Parse and validate a face descriptor JSON array from the client.
 * @return list<float>|null
 */
function client_face_parse_descriptor(mixed $raw): ?array {
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
    } elseif (is_array($raw)) {
        $decoded = $raw;
    } else {
        return null;
    }
    if (!is_array($decoded) || $decoded === []) {
        return null;
    }
    $out = [];
    foreach ($decoded as $v) {
        if (!is_numeric($v)) {
            return null;
        }
        $out[] = (float)$v;
    }
    // ponytail: Human/face-api descriptors are typically 128–1024 floats
    $n = count($out);
    if ($n < 64 || $n > 2048) {
        return null;
    }
    return $out;
}

function client_face_encode_descriptor(array $descriptor): string {
    return json_encode(array_values($descriptor), JSON_UNESCAPED_SLASHES);
}

/**
 * Whether the clients face columns exist (older DBs before migration).
 */
function client_face_columns_exist(PDO $pdo): bool {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        if (db_is_pgsql()) {
            $stmt = $pdo->query(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = 'clients' AND column_name = 'face_descriptor'
                 LIMIT 1"
            );
        } else {
            $stmt = $pdo->query("SHOW COLUMNS FROM clients LIKE 'face_descriptor'");
        }
        $cached = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}
