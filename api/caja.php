<?php
$user = auth_require();
$method = get_method();
$pdo = db();

require_once __DIR__ . '/../includes/company-flags.php';
require_once __DIR__ . '/../includes/caja-balance.php';

$action = $_GET['action'] ?? '';

function caja_store_id(?array $data = null): int {
    $requested = null;
    if (!empty($_GET['store_id'])) {
        $requested = (int)$_GET['store_id'];
    } elseif ($data !== null && !empty($data['store_id'])) {
        $requested = (int)$data['store_id'];
    }
    return resolve_store_id($requested);
}

function caja_fetch_session(PDO $pdo, int $sessionId, int $storeId): array {
    $stmt = $pdo->prepare('SELECT * FROM caja_sessions WHERE id = ? AND store_id = ?');
    $stmt->execute([$sessionId, $storeId]);
    $session = $stmt->fetch();
    if (!$session) {
        json_error('Session not found', 404);
    }
    return $session;
}

function caja_assert_session(PDO $pdo, int $sessionId, int $storeId): void {
    caja_fetch_session($pdo, $sessionId, $storeId);
}

function caja_assert_open_session(PDO $pdo, int $sessionId, int $storeId): array {
    $session = caja_fetch_session($pdo, $sessionId, $storeId);
    if (($session['status'] ?? '') !== 'open') {
        json_error('Session is closed', 403);
    }
    return $session;
}

function caja_require_affected(PDOStatement $stmt, string $message = 'Record not found'): void {
    if ($stmt->rowCount() < 1) {
        json_error($message, 404);
    }
}

function caja_assert_entry_mutable(PDO $pdo, int $entryId, int $storeId): array {
    $stmt = $pdo->prepare(
        'SELECT e.*, s.status AS session_status, s.id AS session_id
         FROM caja_entries e
         JOIN caja_sessions s ON s.id = e.session_id
         WHERE e.id = ? AND s.store_id = ?'
    );
    $stmt->execute([$entryId, $storeId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Entry not found', 404);
    }
    if (($row['session_status'] ?? '') !== 'open') {
        json_error('Session is closed', 403);
    }
    return $row;
}

function caja_assert_denom_mutable(PDO $pdo, int $denomId, int $storeId): array {
    $stmt = $pdo->prepare(
        'SELECT d.*, s.status AS session_status, s.id AS session_id
         FROM caja_denominations d
         JOIN caja_sessions s ON s.id = d.session_id
         WHERE d.id = ? AND s.store_id = ?'
    );
    $stmt->execute([$denomId, $storeId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Denomination not found', 404);
    }
    if (($row['session_status'] ?? '') !== 'open') {
        json_error('Session is closed', 403);
    }
    return $row;
}

// GET: List sessions, single session, company flags, balance calculator, shipments
if ($method === 'GET') {
    $storeId = caja_store_id();

    if ($action === 'list_company_flags') {
        json_response([
            'flags' => company_flags_list_active($pdo),
            'can_manage' => auth_is_admin(),
        ]);
    }

    if ($action === 'balance_calculator') {
        $focus = !empty($_GET['focus_store_id']) ? (int)$_GET['focus_store_id'] : $storeId;
        // Admins may view all negatives; non-admins still see cross-store negatives
        // so they know where to send checks, but from_store defaults to theirs.
        json_response([
            'calculator' => caja_balance_calculator($pdo, $focus),
            'current_store_id' => $storeId,
            'stores' => $pdo->query('SELECT id, name FROM stores WHERE ' . sql_is_active('active') . ' ORDER BY name')->fetchAll(),
        ]);
    }

    if ($action === 'list_shipments') {
        $from = !empty($_GET['from_store_id']) ? (int)$_GET['from_store_id'] : null;
        $to = !empty($_GET['to_store_id']) ? (int)$_GET['to_store_id'] : null;
        $company = trim((string)($_GET['company'] ?? ''));
        // Non-admins: only shipments involving their store
        if (!auth_is_admin()) {
            $mine = $storeId;
            $rows = array_values(array_filter(
                caja_list_shipments($pdo, null, null, $company !== '' ? $company : null),
                static fn(array $r): bool => (int)$r['from_store_id'] === $mine || (int)$r['to_store_id'] === $mine
            ));
            if ($from) {
                $rows = array_values(array_filter($rows, static fn(array $r): bool => (int)$r['from_store_id'] === $from));
            }
            if ($to) {
                $rows = array_values(array_filter($rows, static fn(array $r): bool => (int)$r['to_store_id'] === $to));
            }
            json_response(['shipments' => $rows, 'store_id' => $storeId]);
        }
        json_response([
            'shipments' => caja_list_shipments(
                $pdo,
                $from,
                $to,
                $company !== '' ? $company : null
            ),
            'store_id' => $storeId,
        ]);
    }

    if ($action === 'session' && isset($_GET['id'])) {
        $session = caja_fetch_session($pdo, (int)$_GET['id'], $storeId);

        $entries = $pdo->prepare('SELECT * FROM caja_entries WHERE session_id = ? ORDER BY sort_order, id');
        $entries->execute([$session['id']]);
        $entryRows = $entries->fetchAll();

        $denoms = $pdo->prepare('SELECT * FROM caja_denominations WHERE session_id = ? ORDER BY denomination DESC');
        $denoms->execute([$session['id']]);

        $labels = array_map(static fn(array $row): string => (string)($row['company'] ?? ''), $entryRows);

        $shipments = caja_list_shipments($pdo, $storeId, null, null, 30);

        json_response([
            'session'         => $session,
            'entries'         => $entryRows,
            'denominations'   => $denoms->fetchAll(),
            'company_flags'   => company_flags_map_for_labels($pdo, $labels),
            'can_manage_flags'=> auth_is_admin(),
            'shipments'       => $shipments,
            'calculator'      => caja_balance_calculator($pdo, $storeId),
        ]);
    }

    // List sessions for active store
    $date = $_GET['date'] ?? null;
    $where = 'WHERE cs.store_id = ?';
    $params = [$storeId];
    if ($date) {
        $where .= ' AND cs.session_date = ?';
        $params[] = $date;
    }

    $stmt = $pdo->prepare("SELECT cs.*, u.name as user_name, s.name as store_name,
        (SELECT COALESCE(SUM(total),0) FROM caja_entries WHERE session_id = cs.id) as entries_total
        FROM caja_sessions cs LEFT JOIN users u ON u.id = cs.user_id
        LEFT JOIN stores s ON s.id = cs.store_id
        {$where} ORDER BY cs.session_date DESC, cs.id DESC LIMIT 50");
    $stmt->execute($params);

    json_response([
        'sessions' => $stmt->fetchAll(),
        'scope' => 'store',
        'store_id' => $storeId,
        'calculator' => caja_balance_calculator($pdo, $storeId),
    ]);
}

// POST: Create/update (JSON or multipart for check images)
if ($method === 'POST') {
    csrf_verify();
    $multipart = str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data');
    $data = $multipart ? $_POST : get_json_body();
    $storeId = caja_store_id($data);
    $act = $data['action'] ?? '';

    if ($act === 'set_company_flag') {
        company_flag_require_admin();
        validate_required($data, ['company', 'reason']);
        $flag = company_flag_set($pdo, (string)$data['company'], (string)$data['reason'], (int)$user['id']);
        json_response(['success' => true, 'flag' => $flag]);
    }

    if ($act === 'clear_company_flag') {
        company_flag_require_admin();
        validate_required($data, ['company']);
        company_flag_clear($pdo, (string)$data['company'], (int)$user['id']);
        json_response(['success' => true]);
    }

    // Open new session
    if ($act === 'open_session') {
        validate_required($data, ['session_date', 'opening_balance']);
        if (!empty($data['store_id'])) {
            $storeId = resolve_store_id((int)$data['store_id']);
        }
        $stmt = $pdo->prepare('INSERT INTO caja_sessions (store_id, user_id, session_date, cashier_name, opening_balance, cash_received, notes) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([
            $storeId,
            $user['id'],
            $data['session_date'],
            sanitize($data['cashier_name'] ?? $user['name']),
            (float)$data['opening_balance'],
            (float)($data['cash_received'] ?? 0),
            sanitize($data['notes'] ?? ''),
        ]);
        $sessionId = sql_last_insert_id($pdo, 'caja_sessions');

        // Default companies
        $companies = ['CAJA','BARRI','VIAMERICAS','INTERCAMBIO','DINEX','GARDA','VIALINK','JP CHEQUES','COMISIONES'];
        $sort = 0;
        $ins = $pdo->prepare('INSERT INTO caja_entries (session_id, company, cash_in, checks_debits, sort_order) VALUES (?,?,0,0,?)');
        foreach ($companies as $c) {
            $ins->execute([$sessionId, $c, $sort++]);
        }

        // Default denominations
        $denominations = [100, 50, 20, 10, 5, 1];
        $denIns = $pdo->prepare('INSERT INTO caja_denominations (session_id, denomination, count) VALUES (?,?,0)');
        foreach ($denominations as $d) {
            $denIns->execute([$sessionId, $d]);
        }

        json_response(['success' => true, 'session_id' => $sessionId], 201);
    }

    // Update entry
    if ($act === 'update_entry') {
        validate_required($data, ['entry_id']);
        $entryId = (int)$data['entry_id'];
        caja_assert_entry_mutable($pdo, $entryId, $storeId);
        $stmt = $pdo->prepare('UPDATE caja_entries SET cash_in = ?, checks_debits = ?, company = ?, notes = ? WHERE id = ? AND session_id IN (SELECT id FROM caja_sessions WHERE store_id = ? AND status = ?)');
        $stmt->execute([
            (float)($data['cash_in'] ?? 0),
            (float)($data['checks_debits'] ?? 0),
            sanitize($data['company'] ?? ''),
            sanitize($data['notes'] ?? ''),
            $entryId,
            $storeId,
            'open',
        ]);
        caja_require_affected($stmt, 'Entry not found');
        json_response(['success' => true]);
    }

    // Add custom entry
    if ($act === 'add_entry') {
        validate_required($data, ['session_id', 'company']);
        $sessionId = (int)$data['session_id'];
        caja_assert_open_session($pdo, $sessionId, $storeId);
        $stmt = $pdo->prepare('INSERT INTO caja_entries (session_id, company, cash_in, checks_debits, notes, sort_order) VALUES (?,?,?,?,?, (SELECT COALESCE(MAX(e2.sort_order),0)+1 FROM caja_entries e2 WHERE e2.session_id = ?))');
        $stmt->execute([
            $sessionId,
            sanitize($data['company']),
            (float)($data['cash_in'] ?? 0),
            (float)($data['checks_debits'] ?? 0),
            sanitize($data['notes'] ?? ''),
            $sessionId,
        ]);
        json_response(['success' => true, 'entry_id' => sql_last_insert_id($pdo, 'caja_entries')], 201);
    }

    // Delete entry
    if ($act === 'delete_entry') {
        validate_required($data, ['entry_id']);
        $entryId = (int)$data['entry_id'];
        caja_assert_entry_mutable($pdo, $entryId, $storeId);
        $stmt = $pdo->prepare('DELETE FROM caja_entries WHERE id = ? AND session_id IN (SELECT id FROM caja_sessions WHERE store_id = ? AND status = ?)');
        $stmt->execute([$entryId, $storeId, 'open']);
        caja_require_affected($stmt, 'Entry not found');
        json_response(['success' => true]);
    }

    // Update denomination
    if ($act === 'update_denomination') {
        validate_required($data, ['denom_id', 'count']);
        $denomId = (int)$data['denom_id'];
        caja_assert_denom_mutable($pdo, $denomId, $storeId);
        $stmt = $pdo->prepare('UPDATE caja_denominations SET count = ? WHERE id = ? AND session_id IN (SELECT id FROM caja_sessions WHERE store_id = ? AND status = ?)');
        $stmt->execute([(int)$data['count'], $denomId, $storeId, 'open']);
        caja_require_affected($stmt, 'Denomination not found');
        json_response(['success' => true]);
    }

    // Close session
    if ($act === 'close_session') {
        validate_required($data, ['session_id']);
        $sessionId = (int)$data['session_id'];
        caja_assert_open_session($pdo, $sessionId, $storeId);
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(total),0) as t FROM caja_entries WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $total = (float)$stmt->fetch()['t'];

        $upd = $pdo->prepare("UPDATE caja_sessions SET status = 'closed', closing_balance = ?, notes = ? WHERE id = ? AND store_id = ? AND status = 'open'");
        $upd->execute([$total, sanitize($data['notes'] ?? ''), $sessionId, $storeId]);
        caja_require_affected($upd, 'Session not found or already closed');
        json_response(['success' => true, 'closing_balance' => $total]);
    }

    // Update cash received note on an open session
    if ($act === 'update_cash_received') {
        validate_required($data, ['session_id']);
        $sessionId = (int)$data['session_id'];
        caja_assert_open_session($pdo, $sessionId, $storeId);
        $stmt = $pdo->prepare("UPDATE caja_sessions SET cash_received = ? WHERE id = ? AND store_id = ? AND status = 'open'");
        $stmt->execute([(float)($data['cash_received'] ?? 0), $sessionId, $storeId]);
        caja_require_affected($stmt);
        json_response(['success' => true, 'cash_received' => (float)($data['cash_received'] ?? 0)]);
    }

    // Log a check shipment to another store (clears their negative company balance)
    if ($act === 'create_check_shipment') {
        validate_required($data, ['to_store_id', 'company', 'check_amount', 'shipment_date']);
        $toStoreId = (int)$data['to_store_id'];
        $fromStoreId = !empty($data['from_store_id']) ? resolve_store_id((int)$data['from_store_id']) : $storeId;
        if ($toStoreId === $fromStoreId) {
            json_error('Destination store must be different from the origin store.', 422);
        }
        $checkAmount = (float)$data['check_amount'];
        if ($checkAmount <= 0) {
            json_error('Check amount must be greater than zero.', 422);
        }
        // Validate destination store exists
        $chk = $pdo->prepare('SELECT id FROM stores WHERE id = ? AND ' . sql_is_active('active'));
        $chk->execute([$toStoreId]);
        if (!$chk->fetch()) {
            json_error('Destination store not found.', 404);
        }

        $sessionId = !empty($data['session_id']) ? (int)$data['session_id'] : null;
        if ($sessionId) {
            caja_assert_session($pdo, $sessionId, $fromStoreId);
        }

        $paths = [];
        if ($multipart) {
            $files = [];
            if (!empty($_FILES['image']) && is_array($_FILES['image'])) {
                $files[] = $_FILES['image'];
            }
            if (!empty($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
                for ($i = 0, $n = count($_FILES['images']['name']); $i < $n; $i++) {
                    if (($_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                        continue;
                    }
                    $files[] = [
                        'name' => $_FILES['images']['name'][$i],
                        'type' => $_FILES['images']['type'][$i] ?? '',
                        'tmp_name' => $_FILES['images']['tmp_name'][$i],
                        'error' => $_FILES['images']['error'][$i],
                        'size' => $_FILES['images']['size'][$i] ?? 0,
                    ];
                }
            }
            foreach ($files as $file) {
                $stored = upload_file($file, 'caja-checks');
                if ($stored) {
                    $paths[] = $stored;
                }
            }
        }

        $status = sanitize((string)($data['status'] ?? 'sent'));
        if (!in_array($status, ['pending', 'sent', 'applied', 'cancelled'], true)) {
            $status = 'sent';
        }

        $stmt = $pdo->prepare(
            'INSERT INTO caja_check_shipments
            (from_store_id, to_store_id, company, session_id, shipment_date, check_amount, cash_received, check_number, image_path, image_paths_json, notes, status, created_by_user_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $fromStoreId,
            $toStoreId,
            sanitize((string)$data['company']),
            $sessionId,
            $data['shipment_date'],
            $checkAmount,
            (float)($data['cash_received'] ?? 0),
            sanitize((string)($data['check_number'] ?? '')),
            $paths[0] ?? null,
            $paths ? json_encode($paths, JSON_UNESCAPED_SLASHES) : null,
            sanitize((string)($data['notes'] ?? '')),
            $status,
            (int)$user['id'],
        ]);
        $id = sql_last_insert_id($pdo, 'caja_check_shipments');
        $row = $pdo->prepare('SELECT cs.*, fs.name AS from_store_name, ts.name AS to_store_name
            FROM caja_check_shipments cs
            LEFT JOIN stores fs ON fs.id = cs.from_store_id
            LEFT JOIN stores ts ON ts.id = cs.to_store_id
            WHERE cs.id = ?');
        $row->execute([$id]);
        json_response([
            'success' => true,
            'shipment' => caja_shipment_present($row->fetch() ?: ['id' => $id]),
            'calculator' => caja_balance_calculator($pdo, $toStoreId),
        ], 201);
    }

    if ($act === 'update_check_shipment') {
        validate_required($data, ['id']);
        $id = (int)$data['id'];
        $existing = $pdo->prepare('SELECT * FROM caja_check_shipments WHERE id = ?');
        $existing->execute([$id]);
        $row = $existing->fetch();
        if (!$row) {
            json_error('Shipment not found', 404);
        }
        if (!auth_is_admin() && (int)$row['from_store_id'] !== $storeId && (int)$row['to_store_id'] !== $storeId) {
            json_error('Access denied', 403);
        }
        $status = array_key_exists('status', $data) ? sanitize((string)$data['status']) : $row['status'];
        if (!in_array($status, ['pending', 'sent', 'applied', 'cancelled'], true)) {
            json_error('Invalid status', 422);
        }
        $stmt = $pdo->prepare(
            'UPDATE caja_check_shipments SET status = ?, notes = ?, cash_received = ?, check_number = ? WHERE id = ?'
        );
        $stmt->execute([
            $status,
            sanitize((string)($data['notes'] ?? $row['notes'] ?? '')),
            array_key_exists('cash_received', $data) ? (float)$data['cash_received'] : (float)$row['cash_received'],
            sanitize((string)($data['check_number'] ?? $row['check_number'] ?? '')),
            $id,
        ]);
        json_response(['success' => true]);
    }

    if ($act === 'delete_check_shipment') {
        validate_required($data, ['id']);
        $id = (int)$data['id'];
        $existing = $pdo->prepare('SELECT * FROM caja_check_shipments WHERE id = ?');
        $existing->execute([$id]);
        $row = $existing->fetch();
        if (!$row) {
            json_error('Shipment not found', 404);
        }
        if (!auth_is_admin() && (int)$row['from_store_id'] !== $storeId) {
            json_error('Only the origin store or an admin can delete this shipment', 403);
        }
        $pdo->prepare('DELETE FROM caja_check_shipments WHERE id = ?')->execute([$id]);
        json_response(['success' => true]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
