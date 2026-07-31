<?php
$user = auth_require();
$method = get_method();
$pdo = db();
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/client-activity.php';
require_once __DIR__ . '/../includes/transfer-security.php';

function fincen_period_options(): array {
    return [
        'month' => [
            'label' => 'This Calendar Month',
            'sql'   => 'date_sent >= ' . sql_month_start(0),
        ],
        'previous_month' => [
            'label' => 'Previous Calendar Month',
            'sql'   => 'date_sent >= ' . sql_month_start(1) . ' AND date_sent < ' . sql_month_start(0),
        ],
        '3months' => [
            'label' => 'Last 3 Calendar Months',
            'sql'   => 'date_sent >= ' . sql_month_start(2),
        ],
        '6months' => [
            'label' => 'Last 6 Calendar Months',
            'sql'   => 'date_sent >= ' . sql_month_start(5),
        ],
        '12months' => [
            'label' => 'Last 12 Calendar Months',
            'sql'   => 'date_sent >= ' . sql_month_start(11),
        ],
        'lifetime' => [
            'label' => 'Lifetime Total',
            'sql'   => '1=1',
        ],
    ];
}

/** Inclusive date window used for FinCEN period usage (YYYY-MM-DD). */
function fincen_period_date_window(string $period): array {
    $today = new DateTimeImmutable('today');
    $monthStart = $today->modify('first day of this month');

    switch ($period) {
        case 'previous_month':
            $from = $monthStart->modify('-1 month');
            $to = $monthStart->modify('-1 day');
            break;
        case '3months':
            $from = $monthStart->modify('-2 months');
            $to = $today;
            break;
        case '6months':
            $from = $monthStart->modify('-5 months');
            $to = $today;
            break;
        case '12months':
            $from = $monthStart->modify('-11 months');
            $to = $today;
            break;
        case 'lifetime':
            $from = null;
            $to = $today;
            break;
        case 'month':
        default:
            $from = $monthStart;
            $to = $today;
            break;
    }

    return [
        'from' => $from ? $from->format('Y-m-d') : null,
        'to'   => $to ? $to->format('Y-m-d') : null,
    ];
}

function fincen_period_config(?string $period = null): array {
    $options = fincen_period_options();
    $period = $period ?? app_setting('fincen_period', 'month');
    if (!isset($options[$period])) {
        $period = 'month';
    }
    $window = fincen_period_date_window($period);
    $rangeLabel = 'All time';
    if ($window['from'] && $window['to']) {
        $rangeLabel = $window['from'] . ' → ' . $window['to'];
    } elseif ($window['to']) {
        $rangeLabel = 'Through ' . $window['to'];
    }
    return [
        'key'         => $period,
        'label'       => $options[$period]['label'],
        'sql'         => $options[$period]['sql'],
        'date_from'   => $window['from'],
        'date_to'     => $window['to'],
        'range_label' => $rangeLabel,
    ];
}

function clients_store_transfer_sql(int $storeId): string {
    return ' AND store_id = ' . (int)$storeId;
}

function clients_list_store_where(int $storeId): string {
    return 'EXISTS (SELECT 1 FROM transfers t_scope WHERE t_scope.client_id = c.id AND t_scope.store_id = ' . (int)$storeId . ')';
}

/** Synthetic bucket clients created from nameless Money Orders — not FinCEN senders. */
function clients_is_service_bucket_sql(string $alias = 'c'): string {
    return "(LOWER(TRIM({$alias}.name)) IN ('money order', 'giro postal', 'otros servicios', 'other services'))";
}

if ($method === 'GET') {
    $storeFilter = resolve_store_filter(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
    $storeId = $storeFilter ?? resolve_store_id(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
    $action = $_GET['action'] ?? 'list';

    if ($action === 'other_services') {
        $limit = min(300, max(20, (int)($_GET['limit'] ?? 150)));
        $params = [];
        $storeSql = '';
        if ($storeFilter) {
            $storeSql = ' AND bt.store_id = ?';
            $params[] = $storeId;
        }
        // Nameless MOs: no client, or legacy bucket name / reference-as-name
        $sql = "SELECT bt.id, bt.reference_number, bt.transaction_date, bt.transaction_type,
                       bt.customer_name, bt.principal, bt.fee, bt.tax, bt.total,
                       bt.store_id, s.name AS store_name,
                       br.id AS report_id, br.original_name AS report_original_name,
                       br.filename AS report_filename, br.company AS report_company,
                       br.report_date_from, br.report_date_to
                FROM barri_transactions bt
                LEFT JOIN stores s ON s.id = bt.store_id
                LEFT JOIN barri_reports br ON br.id = bt.report_id
                WHERE REPLACE(LOWER(COALESCE(bt.transaction_type, '')), ' ', '_') = 'money_order'
                  AND (
                        bt.client_id IS NULL
                     OR LOWER(TRIM(COALESCE(bt.customer_name, ''))) IN ('money order', 'giro postal', '')
                     OR TRIM(COALESCE(bt.customer_name, '')) = TRIM(COALESCE(bt.reference_number, ''))
                     OR bt.customer_name LIKE 'MO %'
                  )
                  {$storeSql}
                ORDER BY bt.transaction_date DESC NULLS LAST, bt.id DESC
                LIMIT {$limit}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $sumPrincipal = 0.0;
        $sumFees = 0.0;
        foreach ($rows as $row) {
            $sumPrincipal += (float)($row['principal'] ?? 0);
            $sumFees += (float)($row['fee'] ?? 0);
        }

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM barri_transactions bt
             WHERE REPLACE(LOWER(COALESCE(bt.transaction_type, '')), ' ', '_') = 'money_order'
               AND (
                     bt.client_id IS NULL
                  OR LOWER(TRIM(COALESCE(bt.customer_name, ''))) IN ('money order', 'giro postal', '')
                  OR TRIM(COALESCE(bt.customer_name, '')) = TRIM(COALESCE(bt.reference_number, ''))
                  OR bt.customer_name LIKE 'MO %'
               ){$storeSql}"
        );
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        json_response([
            'transactions' => $rows,
            'total' => $total,
            'shown' => count($rows),
            'sum_principal' => $sumPrincipal,
            'sum_fees' => $sumFees,
            'note' => 'Viamericas Money Orders have no client names in Estado de Cuenta PDFs.',
        ]);
    }

    if ($action === 'detail' && isset($_GET['id'])) {
        $clientId = (int)$_GET['id'];
        auth_require_client_store_access($pdo, $clientId);
        $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $client = $stmt->fetch();
        if (!$client) json_error('Client not found', 404);
        $isServiceBucket = in_array(
            strtolower(trim((string)($client['name'] ?? ''))),
            ['money order', 'giro postal', 'otros servicios', 'other services'],
            true
        );

        $storeParams = [$clientId, $storeId];

        // Monthly usage for current month (scoped to store for non-admins)
        $month = $_GET['month'] ?? date('Y-m');
        $monthStart = $month . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        if (auth_is_admin()) {
            $usage = $pdo->prepare('SELECT COALESCE(SUM(amount_usd),0) as total FROM transfers WHERE client_id = ? AND date_sent BETWEEN ? AND ?');
            $usage->execute([$clientId, $monthStart, $monthEnd . ' 23:59:59']);
        } else {
            $usage = $pdo->prepare('SELECT COALESCE(SUM(amount_usd),0) as total FROM transfers WHERE client_id = ? AND store_id = ? AND date_sent BETWEEN ? AND ?');
            $usage->execute([$clientId, $storeId, $monthStart, $monthEnd . ' 23:59:59']);
        }
        $monthUsage = (float)$usage->fetch()['total'];

        if (auth_is_admin()) {
            $transfers = $pdo->prepare(
                'SELECT t.*, s.name AS store_name,
                        bt.id AS barri_txn_id,
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
                 ORDER BY t.date_sent DESC, t.id DESC
                 LIMIT 100'
            );
            $transfers->execute([$clientId]);
            $monthExpr = sql_date_format_ym('date_sent');
            $monthlySummary = $pdo->prepare("SELECT {$monthExpr} as month, COUNT(*) as cnt, SUM(amount_usd) as total FROM transfers WHERE client_id = ? GROUP BY {$monthExpr} ORDER BY month DESC LIMIT 12");
            $monthlySummary->execute([$clientId]);
            $companyBreakdown = $pdo->prepare(
                "SELECT COALESCE(NULLIF(TRIM(company), ''), 'Unknown') AS company,
                        COUNT(*) AS cnt,
                        COALESCE(SUM(amount_usd), 0) AS total
                 FROM transfers
                 WHERE client_id = ?
                 GROUP BY COALESCE(NULLIF(TRIM(company), ''), 'Unknown')
                 ORDER BY total DESC"
            );
            $companyBreakdown->execute([$clientId]);
        } else {
            $transfers = $pdo->prepare(
                'SELECT t.*, s.name AS store_name,
                        bt.id AS barri_txn_id,
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
                 ORDER BY t.date_sent DESC, t.id DESC
                 LIMIT 100'
            );
            $transfers->execute($storeParams);
            $monthExpr = sql_date_format_ym('date_sent');
            $monthlySummary = $pdo->prepare("SELECT {$monthExpr} as month, COUNT(*) as cnt, SUM(amount_usd) as total FROM transfers WHERE client_id = ? AND store_id = ? GROUP BY {$monthExpr} ORDER BY month DESC LIMIT 12");
            $monthlySummary->execute($storeParams);
            $companyBreakdown = $pdo->prepare(
                "SELECT COALESCE(NULLIF(TRIM(company), ''), 'Unknown') AS company,
                        COUNT(*) AS cnt,
                        COALESCE(SUM(amount_usd), 0) AS total
                 FROM transfers
                 WHERE client_id = ? AND store_id = ?
                 GROUP BY COALESCE(NULLIF(TRIM(company), ''), 'Unknown')
                 ORDER BY total DESC"
            );
            $companyBreakdown->execute($storeParams);
        }

        // Receivers
        $receivers = $pdo->prepare('SELECT * FROM receivers WHERE client_id = ? ORDER BY name');
        $receivers->execute([$clientId]);

        json_response([
            'client'            => with_stored_file_urls($client),
            'is_service_bucket' => $isServiceBucket,
            'month_usage'       => $monthUsage,
            'month_limit'       => (float)$client['monthly_limit'],
            'transfers'         => $transfers->fetchAll(),
            'company_breakdown' => $companyBreakdown->fetchAll(),
            'monthly_summary'   => $monthlySummary->fetchAll(),
            'receivers'         => array_map(
                fn(array $receiver) => with_stored_file_urls($receiver, ['id_path']),
                $receivers->fetchAll()
            ),
            'activity'          => client_activity_list($pdo, $clientId, 30),
            'security_alerts'   => transfer_security_open_for_client($pdo, $clientId),
        ]);
    }

    // List clients with search and sorting
    $search = trim((string)($_GET['search'] ?? ''));
    $sort = $_GET['sort'] ?? 'name';
    $filter = $_GET['filter'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 50;
    $offset = ($page - 1) * $limit;
    $fincenThreshold = isset($_GET['fincen_threshold'])
        ? max(0, (float)$_GET['fincen_threshold'])
        : app_setting_float('fincen_global_limit', 3000);
    $periodConfig = fincen_period_config($_GET['fincen_period'] ?? null);
    $periodSql = $periodConfig['sql'];

    $sortMap = [
        'name'       => 'c.name ASC',
        'top_sender' => 'total_sent DESC',
        'month_usage'=> 'period_usage DESC',
        'recent'     => 'last_transfer DESC',
        'limit'      => 'c.monthly_limit DESC',
        'fincen'     => 'period_usage DESC',
    ];
    if ($filter === 'fincen') {
        $sort = 'fincen';
    }
    $orderBy = $sortMap[$sort] ?? 'c.name ASC';

    $storeSql = $storeFilter ? clients_store_transfer_sql($storeId) : '';

    $baseSelect = "SELECT c.*,
        (SELECT COALESCE(SUM(amount_usd),0) FROM transfers WHERE client_id = c.id{$storeSql} AND {$periodSql}) as period_usage,
        (SELECT COALESCE(SUM(amount_usd),0) FROM transfers WHERE client_id = c.id{$storeSql} AND date_sent >= " . sql_month_start(0) . ") as month_usage,
        (SELECT COALESCE(SUM(amount_usd),0) FROM transfers WHERE client_id = c.id{$storeSql}) as total_sent,
        (SELECT COUNT(*) FROM transfers WHERE client_id = c.id{$storeSql}) as transfer_count,
        (SELECT MAX(date_sent) FROM transfers WHERE client_id = c.id{$storeSql}) as last_transfer
        FROM clients c";

    if ($storeFilter) {
        $where = [clients_list_store_where($storeId)];
    } elseif ($search !== '') {
        // Searching: include clients even if they have no transfers yet
        $where = ['1=1'];
    } else {
        $where = ['EXISTS (SELECT 1 FROM transfers t_scope WHERE t_scope.client_id = c.id)'];
    }
    // Never list synthetic Money Order bucket clients in FinCEN / client roster
    $where[] = 'NOT ' . clients_is_service_bucket_sql('c');
    $params = [];

    if ($search !== '') {
        $tokens = preg_split('/\s+/u', search_fold($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_filter($tokens, static fn($t) => mb_strlen($t) >= 1));
        if ($tokens === []) {
            $tokens = [search_fold($search)];
        }
        $nameFold = sql_fold_text('c.name');
        $codeFold = sql_fold_text('COALESCE(c.client_code, \'\')');
        $phoneFold = sql_fold_text('COALESCE(c.phone, \'\')');
        $likeOp = sql_like_op();
        $tokenClauses = [];
        foreach ($tokens as $token) {
            $like = '%' . $token . '%';
            // Each word must appear somewhere in name/phone/code (AND across tokens)
            $tokenClauses[] = "({$nameFold} {$likeOp} ? OR {$phoneFold} {$likeOp} ? OR {$codeFold} {$likeOp} ?)";
            array_push($params, $like, $like, $like);
        }
        if ($tokenClauses) {
            $where[] = '(' . implode(' AND ', $tokenClauses) . ')';
        }
    }

    if ($filter === 'fincen') {
        $where[] = "(SELECT COALESCE(SUM(amount_usd),0) FROM transfers WHERE client_id = c.id{$storeSql} AND {$periodSql}) >= ?";
        $params[] = $fincenThreshold;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM clients c {$whereSql}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $listParams = array_merge($params, [$limit, $offset]);
    $stmt = $pdo->prepare("{$baseSelect} {$whereSql} ORDER BY {$orderBy} LIMIT ? OFFSET ?");
    $stmt->execute($listParams);

    json_response([
        'clients'          => $stmt->fetchAll(),
        'total'            => $total,
        'page'             => $page,
        'pages'            => (int)ceil(max(1, $total) / $limit),
        'filter'           => $filter,
        'fincen_threshold'      => $fincenThreshold,
        'fincen_global_limit'   => $fincenThreshold,
        'fincen_period'         => $periodConfig['key'],
        'fincen_period_label'   => $periodConfig['label'],
        'fincen_period_range'   => $periodConfig['range_label'],
        'fincen_period_from'    => $periodConfig['date_from'],
        'fincen_period_to'      => $periodConfig['date_to'],
        'fincen_period_options' => array_map(
            fn(string $key) => ['value' => $key, 'label' => fincen_period_options()[$key]['label']],
            array_keys(fincen_period_options())
        ),
        'scope' => $storeFilter ? 'store' : 'all',
    ]);
}

if ($method === 'POST') {
    csrf_verify();
    $requestedStore = !empty($_POST['store_id']) ? (int)$_POST['store_id'] : null;

    // Handle multipart form uploads
    if (!empty($_POST['action']) && in_array($_POST['action'], ['upload_id', 'upload_receiver_id', 'upload_income'])) {
        $act = $_POST['action'];

        if ($act === 'upload_income') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            auth_require_client_store_access($pdo, $clientId);
            $newLimit = (float)($_POST['new_limit'] ?? 5000);
            if (empty($_FILES['income_file'])) json_error('No file provided');
            $path = upload_file($_FILES['income_file'], 'income-docs');
            if (!$path) json_error('Upload failed');
            $pdo->prepare('UPDATE clients SET income_doc_path = ?, income_verified = ' . sql_bool(true) . ', monthly_limit = ? WHERE id = ?')
                ->execute([$path, $newLimit, $clientId]);
            client_activity_log($pdo, $clientId, 'income_uploaded', 'Income doc uploaded, limit: $' . number_format($newLimit, 2), (int)$user['id']);
            json_response(['success' => true, 'path' => $path, 'path_url' => stored_file_url($path), 'new_limit' => $newLimit]);
        }

        if ($act === 'upload_id') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            auth_require_client_store_access($pdo, $clientId);
            $idType = sanitize($_POST['id_type'] ?? 'other');
            if (empty($_FILES['id_file'])) json_error('No file provided');
            $path = upload_file($_FILES['id_file'], 'client-ids');
            if (!$path) json_error('Upload failed');
            $pdo->prepare('UPDATE clients SET sender_id_path = ?, sender_id_type = ? WHERE id = ?')
                ->execute([$path, $idType, $clientId]);
            client_activity_log($pdo, $clientId, 'id_uploaded', 'Client ID uploaded (' . $idType . ')', (int)$user['id']);
            json_response(['success' => true, 'path' => $path, 'path_url' => stored_file_url($path)]);
        }

        if ($act === 'upload_receiver_id') {
            $receiverId = (int)($_POST['receiver_id'] ?? 0);
            auth_require_receiver_store_access($pdo, $receiverId);
            $idType = sanitize($_POST['id_type'] ?? 'other');
            $recv = $pdo->prepare('SELECT id FROM receivers WHERE id = ?');
            $recv->execute([$receiverId]);
            if (!$recv->fetch()) json_error('Receiver not found', 404);
            if (empty($_FILES['id_file'])) json_error('No file provided');
            $path = upload_file($_FILES['id_file'], 'receiver-ids');
            if (!$path) json_error('Upload failed');
            $pdo->prepare('UPDATE receivers SET id_path = ?, id_type = ? WHERE id = ?')
                ->execute([$path, $idType, $receiverId]);
            json_response(['success' => true, 'path' => $path, 'path_url' => stored_file_url($path)]);
        }
    }

    $data = get_json_body();
    if (!$requestedStore && !empty($data['store_id'])) {
        $requestedStore = (int)$data['store_id'];
    }
    $storeId = resolve_store_id($requestedStore);
    $act = $data['action'] ?? '';

    if ($act === 'create') {
        validate_required($data, ['name']);
        $stmt = $pdo->prepare('INSERT INTO clients (client_code, name, phone, monthly_limit, notes) VALUES (?,?,?,?,?)');
        $stmt->execute([
            sanitize($data['client_code'] ?? ''),
            sanitize($data['name']),
            sanitize($data['phone'] ?? ''),
            (float)($data['monthly_limit'] ?? 3000),
            sanitize($data['notes'] ?? ''),
        ]);
        json_response(['success' => true, 'client_id' => sql_last_insert_id($pdo, 'clients')], 201);
    }

    if ($act === 'update') {
        validate_required($data, ['id', 'name']);
        $clientId = (int)$data['id'];
        auth_require_client_store_access($pdo, $clientId);
        $stmt = $pdo->prepare('UPDATE clients SET client_code = ?, name = ?, phone = ?, monthly_limit = ?, income_verified = ?, notes = ? WHERE id = ?');
        $stmt->execute([
            sanitize($data['client_code'] ?? ''),
            sanitize($data['name']),
            sanitize($data['phone'] ?? ''),
            (float)($data['monthly_limit'] ?? 3000),
            db_bool((bool)($data['income_verified'] ?? false)),
            sanitize($data['notes'] ?? ''),
            (int)$data['id'],
        ]);
        client_activity_log($pdo, $clientId, 'client_updated', 'Client profile updated', (int)$user['id']);
        json_response(['success' => true]);
    }

    if ($act === 'add_transfer') {
        validate_required($data, ['client_id', 'beneficiary', 'amount_usd', 'date_sent']);

        // Check monthly limit
        $clientId = (int)$data['client_id'];
        auth_require_client_store_access($pdo, $clientId);
        $stmt = $pdo->prepare('SELECT monthly_limit FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $client = $stmt->fetch();
        if (!$client) json_error('Client not found', 404);

        $dateSent = $data['date_sent'];
        $monthStart = date('Y-m-01', strtotime($dateSent));
        $monthEnd = date('Y-m-t', strtotime($dateSent));

        $usage = $pdo->prepare('SELECT COALESCE(SUM(amount_usd),0) as total FROM transfers WHERE client_id = ? AND store_id = ? AND date_sent BETWEEN ? AND ?');
        $usage->execute([$clientId, $storeId, $monthStart, $monthEnd . ' 23:59:59']);
        $currentUsage = (float)$usage->fetch()['total'];
        $newAmount = (float)$data['amount_usd'];

        $warning = null;
        if ($currentUsage + $newAmount > (float)$client['monthly_limit']) {
            $warning = "This transfer will exceed the monthly limit of $" . number_format($client['monthly_limit'], 2) . ". Current usage: $" . number_format($currentUsage, 2);
        }

        $stmt = $pdo->prepare('INSERT INTO transfers (client_id, store_id, transaction_code, beneficiary, date_sent, date_paid, amount_usd, amount_local, currency, paying_bank, destination_country, destination_city, company) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $clientId,
            $storeId,
            sanitize($data['transaction_code'] ?? ''),
            sanitize($data['beneficiary']),
            $dateSent,
            $data['date_paid'] ?? null,
            $newAmount,
            (float)($data['amount_local'] ?? 0),
            sanitize($data['currency'] ?? 'MXN'),
            sanitize($data['paying_bank'] ?? ''),
            sanitize($data['destination_country'] ?? ''),
            sanitize($data['destination_city'] ?? ''),
            sanitize($data['company'] ?? ''),
        ]);

        $newTransferId = sql_last_insert_id($pdo, 'transfers');

        client_activity_log($pdo, $clientId, 'transfer_added', "Transfer #{$newTransferId} \${$newAmount} to " . sanitize($data['beneficiary']), (int)$user['id']);
        $secAlerts = transfer_security_scan_transfer($pdo, $newTransferId);

        json_response([
            'success'         => true,
            'transfer_id'     => $newTransferId,
            'warning'         => $warning,
            'security_alerts' => $secAlerts,
        ], 201);
    }

    if ($act === 'verify_income') {
        validate_required($data, ['client_id', 'new_limit']);
        $viClientId = (int)$data['client_id'];
        auth_require_client_store_access($pdo, $viClientId);
        $stmt = $pdo->prepare('UPDATE clients SET income_verified = ' . sql_bool(true) . ', monthly_limit = ? WHERE id = ?');
        $stmt->execute([(float)$data['new_limit'], $viClientId]);
        client_activity_log($pdo, $viClientId, 'income_verified', 'New limit: $' . number_format((float)$data['new_limit'], 2), (int)$user['id']);
        json_response(['success' => true]);
    }

    if ($act === 'update_limit') {
        validate_required($data, ['id', 'monthly_limit']);
        $ulClientId = (int)$data['id'];
        auth_require_client_store_access($pdo, $ulClientId);
        $newLimit = max(0, (float)$data['monthly_limit']);
        $stmt = $pdo->prepare('UPDATE clients SET monthly_limit = ? WHERE id = ?');
        $stmt->execute([$newLimit, $ulClientId]);
        if ($stmt->rowCount() === 0) json_error('Client not found', 404);
        client_activity_log($pdo, $ulClientId, 'limit_updated', 'New limit: $' . number_format($newLimit, 2), (int)$user['id']);
        json_response(['success' => true, 'monthly_limit' => $newLimit]);
    }

    if ($act === 'update_fincen_global_limit') {
        if (($user['role'] ?? '') !== 'admin') {
            json_error('Only admins can change the global FinCEN limit.', 403);
        }
        validate_required($data, ['fincen_global_limit']);
        $newLimit = max(0, (float)$data['fincen_global_limit']);
        app_setting_set('fincen_global_limit', (string)$newLimit);

        $period = $data['fincen_period'] ?? null;
        if ($period !== null) {
            $periodConfig = fincen_period_config((string)$period);
            app_setting_set('fincen_period', $periodConfig['key']);
        } else {
            $periodConfig = fincen_period_config();
        }

        $syncStmt = $pdo->prepare('UPDATE clients SET monthly_limit = ?');
        $syncStmt->execute([$newLimit]);
        json_response([
            'success'             => true,
            'fincen_global_limit' => $newLimit,
            'fincen_period'       => $periodConfig['key'],
            'fincen_period_label' => $periodConfig['label'],
            'fincen_period_range' => $periodConfig['range_label'],
            'fincen_period_from'  => $periodConfig['date_from'],
            'fincen_period_to'    => $periodConfig['date_to'],
            'clients_synced'      => $syncStmt->rowCount(),
        ]);
    }

    if ($act === 'add_receiver') {
        validate_required($data, ['client_id', 'name']);
        $arClientId = (int)$data['client_id'];
        auth_require_client_store_access($pdo, $arClientId);
        $stmt = $pdo->prepare('INSERT INTO receivers (client_id, name, phone, destination_country, destination_city, notes) VALUES (?,?,?,?,?,?)');
        $stmt->execute([
            $arClientId, sanitize($data['name']),
            sanitize($data['phone'] ?? ''), sanitize($data['destination_country'] ?? ''),
            sanitize($data['destination_city'] ?? ''), sanitize($data['notes'] ?? '')
        ]);
        $newRecId = sql_last_insert_id($pdo, 'receivers');
        client_activity_log($pdo, $arClientId, 'receiver_added', 'Receiver: ' . sanitize($data['name']), (int)$user['id']);
        json_response(['success' => true, 'receiver_id' => $newRecId], 201);
    }

    if ($act === 'update_receiver') {
        validate_required($data, ['id', 'name']);
        auth_require_receiver_store_access($pdo, (int)$data['id']);
        $stmt = $pdo->prepare('UPDATE receivers SET name = ?, phone = ?, destination_country = ?, destination_city = ?, notes = ? WHERE id = ?');
        $stmt->execute([
            sanitize($data['name']), sanitize($data['phone'] ?? ''),
            sanitize($data['destination_country'] ?? ''), sanitize($data['destination_city'] ?? ''),
            sanitize($data['notes'] ?? ''), (int)$data['id']
        ]);
        json_response(['success' => true]);
    }

    if ($act === 'delete_receiver') {
        validate_required($data, ['id']);
        auth_require_receiver_store_access($pdo, (int)$data['id']);
        $pdo->prepare('DELETE FROM receivers WHERE id = ?')->execute([(int)$data['id']]);
        json_response(['success' => true]);
    }

    if ($act === 'list_receivers') {
        validate_required($data, ['client_id']);
        auth_require_client_store_access($pdo, (int)$data['client_id']);
        $stmt = $pdo->prepare('SELECT * FROM receivers WHERE client_id = ? ORDER BY name');
        $stmt->execute([(int)$data['client_id']]);
        json_response(['receivers' => $stmt->fetchAll()]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
