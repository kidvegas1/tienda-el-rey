<?php

$user = auth_require();
$method = get_method();
$pdo = db();

require_once __DIR__ . '/../includes/client-activity.php';
require_once __DIR__ . '/../includes/transfer-security.php';

$hasRequestsTable = null;
function _cr_has_requests_table(PDO $pdo): bool {
    global $hasRequestsTable;
    if ($hasRequestsTable !== null) return $hasRequestsTable;
    try {
        $pdo->query('SELECT 1 FROM client_record_requests LIMIT 1');
        $hasRequestsTable = true;
    } catch (\Throwable) {
        $hasRequestsTable = false;
    }
    return $hasRequestsTable;
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'search';

    if ($action === 'search') {
        $name = trim($_GET['name'] ?? '');
        if (mb_strlen($name) < 2) {
            json_error('Search requires at least 2 characters', 400);
        }

        $storeFilter = resolve_store_filter(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
        $tokens = preg_split('/\s+/u', search_fold($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_filter($tokens, static fn($t) => mb_strlen($t) >= 1));
        if ($tokens === []) {
            $tokens = [search_fold($name)];
        }
        $nameFold = sql_fold_text('c.name');
        $likeOp = sql_like_op();
        $tokenSql = [];
        $tokenParams = [];
        foreach ($tokens as $token) {
            $tokenSql[] = "{$nameFold} {$likeOp} ?";
            $tokenParams[] = '%' . $token . '%';
        }
        $nameWhere = implode(' AND ', $tokenSql);

        if ($storeFilter !== null) {
            $stmt = $pdo->prepare(
                "SELECT c.*,
                    (SELECT COALESCE(SUM(amount_usd),0) FROM transfers WHERE client_id = c.id AND store_id = ?) as total_sent,
                    (SELECT COUNT(*) FROM transfers WHERE client_id = c.id AND store_id = ?) as transfer_count,
                    (SELECT MAX(date_sent) FROM transfers WHERE client_id = c.id AND store_id = ?) as last_transfer
                 FROM clients c
                 WHERE {$nameWhere}
                   AND EXISTS (SELECT 1 FROM transfers t_scope WHERE t_scope.client_id = c.id AND t_scope.store_id = ?)
                 ORDER BY c.name LIMIT 25"
            );
            $stmt->execute(array_merge([$storeFilter, $storeFilter, $storeFilter], $tokenParams, [$storeFilter]));
        } else {
            $stmt = $pdo->prepare(
                "SELECT c.*,
                    (SELECT COALESCE(SUM(amount_usd),0) FROM transfers WHERE client_id = c.id) as total_sent,
                    (SELECT COUNT(*) FROM transfers WHERE client_id = c.id) as transfer_count,
                    (SELECT MAX(date_sent) FROM transfers WHERE client_id = c.id) as last_transfer
                 FROM clients c
                 WHERE {$nameWhere}
                 ORDER BY c.name LIMIT 25"
            );
            $stmt->execute($tokenParams);
        }

        json_response(['clients' => $stmt->fetchAll()]);
    }

    if ($action === 'dossier') {
        $clientId = (int)($_GET['client_id'] ?? 0);
        if ($clientId <= 0) json_error('client_id required', 400);

        auth_require_client_store_access($pdo, $clientId);

        $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $client = $stmt->fetch();
        if (!$client) json_error('Client not found', 404);

        $receivers = $pdo->prepare('SELECT * FROM receivers WHERE client_id = ? ORDER BY name');
        $receivers->execute([$clientId]);

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(200, max(1, (int)($_GET['limit'] ?? 100)));
        $offset = ($page - 1) * $limit;

        if (auth_is_admin()) {
            $tStmt = $pdo->prepare(
                "SELECT t.*, s.name as store_name,
                        bt.reference_number AS report_reference,
                        br.id AS report_id,
                        br.original_name AS report_original_name,
                        br.filename AS report_filename,
                        br.company AS report_company,
                        br.report_type AS report_type,
                        br.report_date_from AS report_date_from,
                        br.report_date_to AS report_date_to
                 FROM transfers t
                 LEFT JOIN stores s ON s.id = t.store_id
                 LEFT JOIN barri_transactions bt ON bt.transfer_id = t.id
                 LEFT JOIN barri_reports br ON br.id = bt.report_id
                 WHERE t.client_id = ?
                 ORDER BY t.date_sent DESC LIMIT ? OFFSET ?"
            );
            $tStmt->execute([$clientId, $limit, $offset]);
            $tCount = $pdo->prepare('SELECT COUNT(*) FROM transfers WHERE client_id = ?');
            $tCount->execute([$clientId]);
            $companyBreakdown = $pdo->prepare(
                "SELECT COALESCE(NULLIF(TRIM(company), ''), 'Unknown') AS company,
                        COUNT(*) AS cnt,
                        COALESCE(SUM(amount_usd), 0) AS total
                 FROM transfers WHERE client_id = ?
                 GROUP BY COALESCE(NULLIF(TRIM(company), ''), 'Unknown')
                 ORDER BY total DESC"
            );
            $companyBreakdown->execute([$clientId]);
        } else {
            $storeId = resolve_store_id();
            $tStmt = $pdo->prepare(
                "SELECT t.*, s.name as store_name,
                        bt.reference_number AS report_reference,
                        br.id AS report_id,
                        br.original_name AS report_original_name,
                        br.filename AS report_filename,
                        br.company AS report_company,
                        br.report_type AS report_type,
                        br.report_date_from AS report_date_from,
                        br.report_date_to AS report_date_to
                 FROM transfers t
                 LEFT JOIN stores s ON s.id = t.store_id
                 LEFT JOIN barri_transactions bt ON bt.transfer_id = t.id
                 LEFT JOIN barri_reports br ON br.id = bt.report_id
                 WHERE t.client_id = ? AND t.store_id = ?
                 ORDER BY t.date_sent DESC LIMIT ? OFFSET ?"
            );
            $tStmt->execute([$clientId, $storeId, $limit, $offset]);
            $tCount = $pdo->prepare('SELECT COUNT(*) FROM transfers WHERE client_id = ? AND store_id = ?');
            $tCount->execute([$clientId, $storeId]);
            $companyBreakdown = $pdo->prepare(
                "SELECT COALESCE(NULLIF(TRIM(company), ''), 'Unknown') AS company,
                        COUNT(*) AS cnt,
                        COALESCE(SUM(amount_usd), 0) AS total
                 FROM transfers WHERE client_id = ? AND store_id = ?
                 GROUP BY COALESCE(NULLIF(TRIM(company), ''), 'Unknown')
                 ORDER BY total DESC"
            );
            $companyBreakdown->execute([$clientId, $storeId]);
        }
        $totalTransfers = (int)$tCount->fetchColumn();

        $monthExpr = sql_date_format_ym('date_sent');
        if (auth_is_admin()) {
            $msStmt = $pdo->prepare(
                "SELECT {$monthExpr} as month, COUNT(*) as cnt, COALESCE(SUM(amount_usd),0) as total
                 FROM transfers WHERE client_id = ? GROUP BY {$monthExpr} ORDER BY month DESC LIMIT 24"
            );
            $msStmt->execute([$clientId]);
        } else {
            $storeId = resolve_store_id();
            $msStmt = $pdo->prepare(
                "SELECT {$monthExpr} as month, COUNT(*) as cnt, COALESCE(SUM(amount_usd),0) as total
                 FROM transfers WHERE client_id = ? AND store_id = ? GROUP BY {$monthExpr} ORDER BY month DESC LIMIT 24"
            );
            $msStmt->execute([$clientId, $storeId]);
        }

        $response = [
            'client'            => with_stored_file_urls($client),
            'receivers'         => array_map(fn($r) => with_stored_file_urls($r, ['id_path']), $receivers->fetchAll()),
            'transfers'         => $tStmt->fetchAll(),
            'transfers_total'   => $totalTransfers,
            'transfers_page'    => $page,
            'transfers_pages'   => (int)ceil(max(1, $totalTransfers) / $limit),
            'company_breakdown' => $companyBreakdown->fetchAll(),
            'monthly_summary'   => $msStmt->fetchAll(),
            'activity_log'      => client_activity_list($pdo, $clientId, 50),
            'security_alerts'   => transfer_security_open_for_client($pdo, $clientId),
        ];

        json_response($response);
    }

    if ($action === 'list_requests') {
        if (!_cr_has_requests_table($pdo)) {
            json_error('client_record_requests table not available', 503);
        }

        $canListAll = auth_is_admin() || auth_is_manager();

        if ($canListAll) {
            $stmt = $pdo->query('SELECT * FROM client_record_requests ORDER BY created_at DESC LIMIT 50');
        } else {
            $userId = (int)$_SESSION['user_id'];
            $stmt = $pdo->prepare(
                "SELECT * FROM client_record_requests
                 WHERE fulfilled_by = ? OR (status = 'pending' AND requester_name = ?)
                 ORDER BY created_at DESC LIMIT 50"
            );
            $stmt->execute([$userId, $_SESSION['user_name'] ?? '']);
        }

        json_response(['requests' => $stmt->fetchAll()]);
    }

    json_error('Unknown action', 400);
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $act = $data['action'] ?? '';

    if ($act === 'request') {
        if (!_cr_has_requests_table($pdo)) {
            json_error('client_record_requests table not available', 503);
        }
        validate_required($data, ['requester_name']);

        $matchedId = !empty($data['matched_client_id']) ? (int)$data['matched_client_id'] : null;
        $status = $matchedId ? 'matched' : 'pending';

        $stmt = $pdo->prepare(
            'INSERT INTO client_record_requests (requester_name, requester_phone, date_from, date_to, matched_client_id, status, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,NOW())'
        );
        $stmt->execute([
            sanitize($data['requester_name']),
            sanitize($data['requester_phone'] ?? ''),
            $data['date_from'] ?? null,
            $data['date_to'] ?? null,
            $matchedId,
            $status,
            (int)$_SESSION['user_id'],
        ]);

        json_response(['success' => true, 'id' => sql_last_insert_id($pdo, 'client_record_requests'), 'status' => $status], 201);
    }

    if ($act === 'fulfill') {
        if (!_cr_has_requests_table($pdo)) {
            json_error('client_record_requests table not available', 503);
        }
        validate_required($data, ['id']);
        $id = (int)$data['id'];

        $stmt = $pdo->prepare('SELECT * FROM client_record_requests WHERE id = ?');
        $stmt->execute([$id]);
        $req = $stmt->fetch();
        if (!$req) json_error('Request not found', 404);

        $clientId = (int)($req['matched_client_id'] ?? 0);
        if ($clientId <= 0 && !empty($data['client_id'])) {
            $clientId = (int)$data['client_id'];
        }
        if ($clientId <= 0) {
            json_error('matched_client_id required to fulfill', 400);
        }

        $upd = $pdo->prepare(
            'UPDATE client_record_requests SET matched_client_id = ?, status = ?, fulfilled_by = ?, fulfilled_at = NOW() WHERE id = ?'
        );
        $upd->execute([$clientId, 'fulfilled', (int)$_SESSION['user_id'], $id]);

        client_activity_log($pdo, $clientId, 'record_fulfilled', "Request #{$id} fulfilled");

        $tCount = $pdo->prepare('SELECT COUNT(*) FROM transfers WHERE client_id = ?');
        $tCount->execute([$clientId]);
        $rCount = $pdo->prepare('SELECT COUNT(*) FROM receivers WHERE client_id = ?');
        $rCount->execute([$clientId]);

        json_response([
            'success'    => true,
            'client_id'  => $clientId,
            'summary'    => [
                'transfers' => (int)$tCount->fetchColumn(),
                'receivers' => (int)$rCount->fetchColumn(),
            ],
        ]);
    }

    if ($act === 'deny') {
        if (!_cr_has_requests_table($pdo)) {
            json_error('client_record_requests table not available', 503);
        }
        validate_required($data, ['id']);

        $stmt = $pdo->prepare('SELECT id FROM client_record_requests WHERE id = ?');
        $stmt->execute([(int)$data['id']]);
        if (!$stmt->fetch()) json_error('Request not found', 404);

        $pdo->prepare('UPDATE client_record_requests SET status = ?, notes = ? WHERE id = ?')
            ->execute(['denied', sanitize($data['notes'] ?? ''), (int)$data['id']]);

        json_response(['success' => true]);
    }

    if ($act === 'export_csv') {
        validate_required($data, ['client_id']);
        $clientId = (int)$data['client_id'];
        auth_require_client_store_access($pdo, $clientId);

        if (auth_is_admin()) {
            $stmt = $pdo->prepare(
                "SELECT t.date_sent, t.amount_usd, t.beneficiary, t.company, t.transaction_code,
                        t.destination_country, t.destination_city, s.name as store_name
                 FROM transfers t LEFT JOIN stores s ON s.id = t.store_id
                 WHERE t.client_id = ? ORDER BY t.date_sent DESC"
            );
            $stmt->execute([$clientId]);
        } else {
            $storeId = resolve_store_id();
            $stmt = $pdo->prepare(
                "SELECT t.date_sent, t.amount_usd, t.beneficiary, t.company, t.transaction_code,
                        t.destination_country, t.destination_city, s.name as store_name
                 FROM transfers t LEFT JOIN stores s ON s.id = t.store_id
                 WHERE t.client_id = ? AND t.store_id = ? ORDER BY t.date_sent DESC"
            );
            $stmt->execute([$clientId, $storeId]);
        }

        $rows = $stmt->fetchAll();
        $header = "Date,Amount USD,Beneficiary,Company,Transaction Code,Country,City,Store\n";
        $csv = $header;
        foreach ($rows as $r) {
            $csv .= implode(',', [
                $r['date_sent'] ?? '',
                $r['amount_usd'] ?? '0',
                '"' . str_replace('"', '""', $r['beneficiary'] ?? '') . '"',
                '"' . str_replace('"', '""', $r['company'] ?? '') . '"',
                '"' . str_replace('"', '""', $r['transaction_code'] ?? '') . '"',
                '"' . str_replace('"', '""', $r['destination_country'] ?? '') . '"',
                '"' . str_replace('"', '""', $r['destination_city'] ?? '') . '"',
                '"' . str_replace('"', '""', $r['store_name'] ?? '') . '"',
            ]) . "\n";
        }

        client_activity_log($pdo, $clientId, 'record_exported', 'CSV export with ' . count($rows) . ' transfers');

        $cStmt = $pdo->prepare('SELECT name FROM clients WHERE id = ?');
        $cStmt->execute([$clientId]);
        $clientName = $cStmt->fetchColumn() ?: 'client';
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $clientName);

        json_response([
            'success'  => true,
            'csv'      => $csv,
            'filename' => "transfers_{$safeName}_" . date('Y-m-d') . '.csv',
            'rows'     => count($rows),
        ]);
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
