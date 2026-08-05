<?php
$user = auth_require();
$method = get_method();
$pdo = db();
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/client-activity.php';
require_once __DIR__ . '/../includes/transfer-security.php';
require_once __DIR__ . '/../includes/commission.php';
require_once __DIR__ . '/../includes/client-face.php';

function clients_activity_mode(?string $raw): string {
    $mode = strtolower(trim((string)$raw));
    return in_array($mode, ['both', 'envios', 'checks'], true) ? $mode : 'both';
}

function clients_check_match_sql(string $clientAlias = 'c', string $btAlias = 'bt'): string {
    $nameMatch = sql_fold_text("{$btAlias}.customer_name") . ' = ' . sql_fold_text("{$clientAlias}.name");
    // Prefer explicit client_id. Name-match only when the check is unlinked,
    // so one check cannot count toward two different clients.
    return "({$btAlias}.client_id = {$clientAlias}.id OR ({$btAlias}.client_id IS NULL AND {$nameMatch}))";
}

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

        $activityMode = clients_activity_mode($_GET['activity'] ?? 'both');
        $checkTypeSql = commission_check_type_sql('bt.transaction_type');
        $checkMatchSql = clients_check_match_sql('c', 'bt');
        $storeBtSql = $storeFilter ? ' AND bt.store_id = ' . (int)$storeId : '';
        $storeTSql = (!auth_is_admin() || $storeFilter) ? ' AND t.store_id = ' . (int)$storeId : '';
        if (auth_is_admin() && !$storeFilter) {
            $storeTSql = '';
        }

        // ponytail: cast date_sent to text so UNION ALL with check dates doesn't type-mismatch on PG
        $envioDateExpr = db_is_pgsql() ? 't.date_sent::text' : 't.date_sent';
        $envioSql = "SELECT t.id, {$envioDateExpr} AS date_sent, t.amount_usd, t.fee, t.tax, t.beneficiary, t.company,
                            t.transaction_type, t.transaction_code, t.source, t.paying_bank,
                            t.destination_country, t.destination_city, t.store_id,
                            s.name AS store_name,
                            bt.id AS barri_txn_id,
                            bt.reference_number AS report_reference,
                            br.id AS report_id,
                            br.original_name AS report_original_name,
                            br.filename AS report_filename,
                            br.company AS report_company,
                            br.report_type AS report_type,
                            br.report_date_from AS report_date_from,
                            br.report_date_to AS report_date_to,
                            'envio' AS activity_kind
                     FROM transfers t
                     LEFT JOIN stores s ON s.id = t.store_id
                     LEFT JOIN barri_transactions bt ON bt.transfer_id = t.id
                     LEFT JOIN barri_reports br ON br.id = bt.report_id
                     WHERE t.client_id = ?{$storeTSql}
                       AND NOT " . commission_check_type_sql('t.transaction_type');

        $checkSql = "SELECT bt.id, (bt.transaction_date::text || ' ' || COALESCE(bt.transaction_time::text, '00:00:00')) AS date_sent,
                            ABS(bt.principal) AS amount_usd, bt.fee, bt.tax,
                            COALESCE(NULLIF(TRIM(bt.description), ''), bt.customer_name) AS beneficiary,
                            COALESCE(br.company, 'Viamericas') AS company,
                            bt.transaction_type, bt.reference_number AS transaction_code,
                            'check_cashing' AS source, NULL AS paying_bank,
                            NULL AS destination_country, NULL AS destination_city, bt.store_id,
                            s.name AS store_name,
                            bt.id AS barri_txn_id,
                            bt.reference_number AS report_reference,
                            br.id AS report_id,
                            br.original_name AS report_original_name,
                            br.filename AS report_filename,
                            br.company AS report_company,
                            br.report_type AS report_type,
                            br.report_date_from AS report_date_from,
                            br.report_date_to AS report_date_to,
                            'check_cashing' AS activity_kind
                     FROM barri_transactions bt
                     JOIN clients c ON c.id = ?
                     LEFT JOIN stores s ON s.id = bt.store_id
                     LEFT JOIN barri_reports br ON br.id = bt.report_id
                     WHERE {$checkTypeSql}
                       AND {$checkMatchSql}
                       {$storeBtSql}";

        // Postgres supports ::text casts used above; keep portable date expression.
        if (!db_is_pgsql()) {
            $checkSql = str_replace(
                "(bt.transaction_date::text || ' ' || COALESCE(bt.transaction_time::text, '00:00:00'))",
                "CONCAT(bt.transaction_date, ' ', COALESCE(bt.transaction_time, '00:00:00'))",
                $checkSql
            );
        }

        if ($activityMode === 'envios') {
            $activitySql = "{$envioSql} ORDER BY date_sent DESC, id DESC LIMIT 200";
            $activityParams = [$clientId];
        } elseif ($activityMode === 'checks') {
            $activitySql = "{$checkSql} ORDER BY date_sent DESC, id DESC LIMIT 200";
            $activityParams = [$clientId];
        } else {
            $activitySql = "({$envioSql}) UNION ALL ({$checkSql}) ORDER BY date_sent DESC, id DESC LIMIT 200";
            $activityParams = [$clientId, $clientId];
        }
        $transfers = $pdo->prepare($activitySql);
        $transfers->execute($activityParams);
        $transferRows = $transfers->fetchAll();

        $checksAgg = $pdo->prepare(
            "SELECT COUNT(*) AS checks_count, COALESCE(SUM(ABS(bt.principal)),0) AS checks_volume
             FROM barri_transactions bt
             JOIN clients c ON c.id = ?
             WHERE {$checkTypeSql} AND {$checkMatchSql}{$storeBtSql}"
        );
        $checksAgg->execute([$clientId]);
        $checksStats = $checksAgg->fetch() ?: ['checks_count' => 0, 'checks_volume' => 0];

        $enviosAgg = $pdo->prepare(
            'SELECT COUNT(*) AS envios_count, COALESCE(SUM(amount_usd),0) AS envios_sent
             FROM transfers t
             WHERE t.client_id = ?' . $storeTSql . ' AND NOT ' . commission_check_type_sql('t.transaction_type')
        );
        $enviosAgg->execute([$clientId]);
        $enviosStats = $enviosAgg->fetch() ?: ['envios_count' => 0, 'envios_sent' => 0];

        $monthExpr = sql_date_format_ym('date_sent');
        if (auth_is_admin() && !$storeFilter) {
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

        $faceEnrolled = !empty($client['face_descriptor']);
        unset($client['face_descriptor']);
        $client['face_enrolled'] = $faceEnrolled;

        json_response([
            'client'            => client_face_with_display_urls($client),
            'is_service_bucket' => $isServiceBucket,
            'month_usage'       => $monthUsage,
            'month_limit'       => (float)$client['monthly_limit'],
            'transfers'         => $transferRows,
            'activity_mode'     => $activityMode,
            'envios_sent'       => (float)$enviosStats['envios_sent'],
            'envios_count'      => (int)$enviosStats['envios_count'],
            'checks_volume'     => (float)$checksStats['checks_volume'],
            'checks_count'      => (int)$checksStats['checks_count'],
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

    if ($action === 'face_roster') {
        if (!client_face_columns_exist($pdo)) {
            json_response(['faces' => [], 'count' => 0, 'available' => false]);
        }
        $stmt = $pdo->query(
            "SELECT id, name, face_descriptor
             FROM clients
             WHERE face_descriptor IS NOT NULL AND TRIM(face_descriptor) <> ''
             ORDER BY name
             LIMIT 5000"
        );
        $faces = [];
        while ($row = $stmt->fetch()) {
            $desc = client_face_parse_descriptor($row['face_descriptor']);
            if ($desc === null) {
                continue;
            }
            $faces[] = [
                'id'         => (int)$row['id'],
                'name'       => (string)$row['name'],
                'descriptor' => $desc,
            ];
        }
        json_response(['faces' => $faces, 'count' => count($faces), 'available' => true]);
    }

    if ($action === 'checks_by_name') {
        $name = trim((string)($_GET['name'] ?? ''));
        if ($name === '') {
            json_error('Name is required', 400);
        }
        $checkTypeSql = commission_check_type_sql('bt.transaction_type');
        $storeSql = '';
        $execParams = [search_fold($name)];
        if ($storeFilter) {
            $storeSql = ' AND bt.store_id = ?';
            $execParams[] = $storeId;
        }
        $nameFold = sql_fold_text('bt.customer_name');
        $dateExpr = db_is_pgsql()
            ? "(bt.transaction_date::text || ' ' || COALESCE(bt.transaction_time::text, '00:00:00'))"
            : "CONCAT(bt.transaction_date, ' ', COALESCE(bt.transaction_time, '00:00:00'))";
        $stmt = $pdo->prepare(
            "SELECT bt.id, {$dateExpr} AS date_sent, ABS(bt.principal) AS amount_usd, bt.fee, bt.tax,
                    COALESCE(NULLIF(TRIM(bt.description), ''), bt.customer_name) AS beneficiary,
                    COALESCE(br.company, 'Viamericas') AS company,
                    bt.transaction_type, bt.reference_number AS transaction_code,
                    'check_cashing' AS source, bt.store_id, s.name AS store_name,
                    bt.id AS barri_txn_id, bt.reference_number AS report_reference,
                    br.id AS report_id, br.original_name AS report_original_name,
                    br.filename AS report_filename, br.company AS report_company,
                    br.report_type AS report_type, br.report_date_from, br.report_date_to,
                    'check_cashing' AS activity_kind, bt.customer_name
             FROM barri_transactions bt
             LEFT JOIN stores s ON s.id = bt.store_id
             LEFT JOIN barri_reports br ON br.id = bt.report_id
             WHERE {$checkTypeSql}
               AND {$nameFold} = ?
               {$storeSql}
             ORDER BY bt.transaction_date DESC, bt.id DESC
             LIMIT 300"
        );
        $stmt->execute($execParams);
        $rows = $stmt->fetchAll();
        $volume = 0.0;
        foreach ($rows as $row) {
            $volume += (float)($row['amount_usd'] ?? 0);
        }
        $clientMatch = $pdo->prepare('SELECT id, name, phone, client_code FROM clients WHERE ' . sql_fold_text('name') . ' = ? LIMIT 1');
        $clientMatch->execute([search_fold($name)]);
        json_response([
            'name' => $name,
            'client' => $clientMatch->fetch() ?: null,
            'checks_count' => count($rows),
            'checks_volume' => $volume,
            'transfers' => $rows,
            'activity_mode' => 'checks',
        ]);
    }

    // List clients with search and sorting
    $search = trim((string)($_GET['search'] ?? ''));
    $sort = $_GET['sort'] ?? 'name';
    $filter = $_GET['filter'] ?? '';
    $activityMode = clients_activity_mode($_GET['activity'] ?? 'both');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 50;
    $offset = ($page - 1) * $limit;
    $fincenThreshold = isset($_GET['fincen_threshold'])
        ? max(0, (float)$_GET['fincen_threshold'])
        : fincen_global_limit();
    $periodConfig = fincen_period_config($_GET['fincen_period'] ?? null);
    $periodSql = $periodConfig['sql'];

    $storeSql = $storeFilter ? clients_store_transfer_sql($storeId) : '';
    $storeBtSql = $storeFilter ? ' AND bt.store_id = ' . (int)$storeId : '';
    $checkTypeSql = commission_check_type_sql('bt.transaction_type');
    $checkMatchSql = clients_check_match_sql('c', 'bt');
    $enviosTypeSql = 'NOT ' . commission_check_type_sql('transaction_type');

    $enviosSentSql = "(SELECT COALESCE(SUM(amount_usd),0) FROM transfers WHERE client_id = c.id{$storeSql} AND {$enviosTypeSql})";
    $enviosCountSql = "(SELECT COUNT(*) FROM transfers WHERE client_id = c.id{$storeSql} AND {$enviosTypeSql})";
    $checksVolumeSql = "(SELECT COALESCE(SUM(ABS(bt.principal)),0) FROM barri_transactions bt WHERE {$checkTypeSql} AND {$checkMatchSql}{$storeBtSql})";
    $checksCountSql = "(SELECT COUNT(*) FROM barri_transactions bt WHERE {$checkTypeSql} AND {$checkMatchSql}{$storeBtSql})";
    $combinedSentSql = "({$enviosSentSql} + {$checksVolumeSql})";

    if ($filter === 'fincen') {
        $sort = 'fincen';
    }

    $topSenderSort = match ($activityMode) {
        'envios' => 'envios_sent DESC, c.name ASC',
        'checks' => 'checks_volume DESC, c.name ASC',
        default  => 'combined_sent DESC, c.name ASC',
    };
    $sortMap = [
        'name'       => 'c.name ASC',
        'top_sender' => $topSenderSort,
        'month_usage'=> 'period_usage DESC',
        'recent'     => 'last_transfer DESC NULLS LAST',
        'limit'      => 'c.monthly_limit DESC',
        'fincen'     => 'period_usage DESC',
    ];
    if (!db_is_pgsql()) {
        $sortMap['recent'] = 'last_transfer DESC';
    }
    $orderBy = $sortMap[$sort] ?? 'c.name ASC';

    // Checks-only roster: aggregate check cashers (including people not yet in clients).
    if ($activityMode === 'checks') {
        $nameFoldBt = sql_fold_text('bt.customer_name');
        $nameFoldC = sql_fold_text('c.name');
        $likeOp = sql_like_op();
        $where = ["TRIM(COALESCE(bt.customer_name, '')) <> ''", $checkTypeSql];
        $params = [];
        if ($storeFilter) {
            $where[] = 'bt.store_id = ?';
            $params[] = $storeId;
        }
        if ($search !== '') {
            $tokens = preg_split('/\s+/u', search_fold($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $tokens = array_values(array_filter($tokens, static fn($t) => mb_strlen($t) >= 1));
            if ($tokens === []) {
                $tokens = [search_fold($search)];
            }
            $tokenClauses = [];
            foreach ($tokens as $token) {
                $like = '%' . $token . '%';
                $tokenClauses[] = "({$nameFoldBt} {$likeOp} ?)";
                $params[] = $like;
            }
            if ($tokenClauses) {
                $where[] = '(' . implode(' AND ', $tokenClauses) . ')';
            }
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $aggFrom = "FROM barri_transactions bt {$whereSql}";
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM (SELECT {$nameFoldBt} AS name_key {$aggFrom} GROUP BY {$nameFoldBt}) x");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $orderChecks = match ($sort) {
            'name' => 'display_name ASC',
            'recent' => db_is_pgsql()
                ? 'last_transfer DESC NULLS LAST, display_name ASC'
                : 'last_transfer DESC, display_name ASC',
            'limit' => 'monthly_limit DESC NULLS LAST, display_name ASC',
            // period_usage is always 0 in checks-only; use check volume for volume sorts.
            'top_sender', 'month_usage', 'fincen' => 'checks_volume DESC, display_name ASC',
            default => 'checks_volume DESC, display_name ASC',
        };
        if (!db_is_pgsql()) {
            $orderChecks = str_replace(' NULLS LAST', '', $orderChecks);
        }
        $listSql = "SELECT
                COALESCE(c.id, 0) AS id,
                COALESCE(c.name, MAX(bt.customer_name)) AS name,
                COALESCE(c.name, MAX(bt.customer_name)) AS display_name,
                c.phone, c.client_code, c.monthly_limit, c.income_verified, c.sender_id_path, c.income_doc_path,
                0::float AS period_usage,
                0::float AS month_usage,
                0::float AS envios_sent,
                0 AS envios_count,
                COALESCE(SUM(ABS(bt.principal)),0) AS checks_volume,
                COUNT(*) AS checks_count,
                COALESCE(SUM(ABS(bt.principal)),0) AS total_sent,
                COUNT(*) AS transfer_count,
                COALESCE(SUM(ABS(bt.principal)),0) AS combined_sent,
                MAX(bt.transaction_date) AS last_transfer,
                'check_casher' AS row_kind
            {$aggFrom}
            LEFT JOIN clients c ON {$nameFoldC} = {$nameFoldBt}
            GROUP BY {$nameFoldBt}, c.id, c.name, c.phone, c.client_code, c.monthly_limit, c.income_verified, c.sender_id_path, c.income_doc_path
            ORDER BY {$orderChecks}
            LIMIT ? OFFSET ?";
        if (!db_is_pgsql()) {
            $listSql = str_replace('0::float', '0', $listSql);
        }
        $stmt = $pdo->prepare($listSql);
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['envios_sent'] = 0.0;
            $row['envios_count'] = 0;
            $row['checks_volume'] = (float)$row['checks_volume'];
            $row['checks_count'] = (int)$row['checks_count'];
            $row['combined_sent'] = (float)$row['combined_sent'];
            $row['is_check_only'] = empty($row['id']);
        }
        unset($row);

        json_response([
            'clients'          => $rows,
            'total'            => $total,
            'page'             => $page,
            'pages'            => (int)ceil(max(1, $total) / $limit),
            'filter'           => $filter,
            'activity'         => $activityMode,
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

    $clientSelectCols = "c.id, c.name, c.phone, c.client_code, c.monthly_limit, c.income_verified, c.sender_id_path, c.income_doc_path,
        (SELECT COALESCE(SUM(amount_usd),0) FROM transfers WHERE client_id = c.id{$storeSql} AND {$periodSql}) as period_usage,
        (SELECT COALESCE(SUM(amount_usd),0) FROM transfers WHERE client_id = c.id{$storeSql} AND date_sent >= " . sql_month_start(0) . ") as month_usage,
        {$enviosSentSql} as envios_sent,
        {$enviosCountSql} as envios_count,
        {$checksVolumeSql} as checks_volume,
        {$checksCountSql} as checks_count,
        {$combinedSentSql} as combined_sent,
        {$enviosSentSql} as total_sent,
        {$enviosCountSql} as transfer_count,
        (SELECT MAX(date_sent) FROM transfers WHERE client_id = c.id{$storeSql}) as last_transfer,
        'client' as row_kind";

    if ($activityMode === 'envios') {
        if ($storeFilter) {
            $where = [clients_list_store_where($storeId)];
        } elseif ($search !== '') {
            $where = ['1=1'];
        } else {
            $where = ['EXISTS (SELECT 1 FROM transfers t_scope WHERE t_scope.client_id = c.id)'];
        }
    } else {
        // both: clients with remittances and/or matched check cashing
        $hasEnvios = 'EXISTS (SELECT 1 FROM transfers t_scope WHERE t_scope.client_id = c.id'
            . ($storeFilter ? ' AND t_scope.store_id = ' . (int)$storeId : '') . ')';
        $hasChecks = "EXISTS (SELECT 1 FROM barri_transactions bt WHERE {$checkTypeSql} AND {$checkMatchSql}{$storeBtSql})";
        if ($search !== '') {
            $where = ['1=1'];
        } else {
            $where = ["({$hasEnvios} OR {$hasChecks})"];
        }
    }
    // Never list synthetic Money Order bucket clients in FinCEN / client roster
    $where[] = 'NOT ' . clients_is_service_bucket_sql('c');
    $params = [];

    $searchTokens = [];
    if ($search !== '') {
        $searchTokens = preg_split('/\s+/u', search_fold($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $searchTokens = array_values(array_filter($searchTokens, static fn($t) => mb_strlen($t) >= 1));
        if ($searchTokens === []) {
            $searchTokens = [search_fold($search)];
        }
        $nameFold = sql_fold_text('c.name');
        $codeFold = sql_fold_text('COALESCE(c.client_code, \'\')');
        $phoneFold = sql_fold_text('COALESCE(c.phone, \'\')');
        $likeOp = sql_like_op();
        $tokenClauses = [];
        foreach ($searchTokens as $token) {
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

    // "Both" includes unmatched check cashers so the roster shows everyone.
    if ($activityMode === 'both' && $filter !== 'fincen') {
        $nameFoldBt = sql_fold_text('bt.customer_name');
        $nameFoldC = sql_fold_text('c.name');
        $likeOp = sql_like_op();
        // Orphans = unlinked checks with no name match. Exclude bt.client_id so
        // checks already attributed to a client (via client_id) are not listed twice.
        $orphanWhere = [
            "TRIM(COALESCE(bt.customer_name, '')) <> ''",
            $checkTypeSql,
            'bt.client_id IS NULL',
            'c.id IS NULL',
        ];
        $orphanParams = [];
        if ($storeFilter) {
            $orphanWhere[] = 'bt.store_id = ?';
            $orphanParams[] = $storeId;
        }
        if ($searchTokens) {
            $tokenClauses = [];
            foreach ($searchTokens as $token) {
                $like = '%' . $token . '%';
                $tokenClauses[] = "({$nameFoldBt} {$likeOp} ?)";
                $orphanParams[] = $like;
            }
            $orphanWhere[] = '(' . implode(' AND ', $tokenClauses) . ')';
        }
        $orphanWhereSql = 'WHERE ' . implode(' AND ', $orphanWhere);
        $zeroFloat = db_is_pgsql() ? '0::float' : '0';
        $clientPart = "SELECT {$clientSelectCols} FROM clients c {$whereSql}";
        $orphanPart = "SELECT
                0 AS id,
                MAX(bt.customer_name) AS name,
                NULL AS phone,
                NULL AS client_code,
                {$zeroFloat} AS monthly_limit,
                " . (db_is_pgsql() ? 'false' : '0') . " AS income_verified,
                NULL AS sender_id_path,
                NULL AS income_doc_path,
                {$zeroFloat} AS period_usage,
                {$zeroFloat} AS month_usage,
                {$zeroFloat} AS envios_sent,
                0 AS envios_count,
                COALESCE(SUM(ABS(bt.principal)),0) AS checks_volume,
                COUNT(*) AS checks_count,
                COALESCE(SUM(ABS(bt.principal)),0) AS combined_sent,
                {$zeroFloat} AS total_sent,
                0 AS transfer_count,
                MAX(bt.transaction_date) AS last_transfer,
                'check_casher' AS row_kind
            FROM barri_transactions bt
            LEFT JOIN clients c ON {$nameFoldC} = {$nameFoldBt}
            {$orphanWhereSql}
            GROUP BY {$nameFoldBt}";

        $unionOrder = match ($sort) {
            'name' => 'name ASC',
            'top_sender' => 'combined_sent DESC, name ASC',
            'month_usage', 'fincen' => 'period_usage DESC, name ASC',
            'recent' => db_is_pgsql() ? 'last_transfer DESC NULLS LAST, name ASC' : 'last_transfer DESC, name ASC',
            'limit' => 'monthly_limit DESC, name ASC',
            default => 'name ASC',
        };

        $countStmt = $pdo->prepare(
            "SELECT (
                (SELECT COUNT(*) FROM clients c {$whereSql})
                + (SELECT COUNT(*) FROM (SELECT {$nameFoldBt} FROM barri_transactions bt LEFT JOIN clients c ON {$nameFoldC} = {$nameFoldBt} {$orphanWhereSql} GROUP BY {$nameFoldBt}) o)
            )"
        );
        $countStmt->execute(array_merge($params, $orphanParams));
        $total = (int)$countStmt->fetchColumn();

        $listSql = "SELECT * FROM (
                ({$clientPart})
                UNION ALL
                ({$orphanPart})
            ) roster
            ORDER BY {$unionOrder}
            LIMIT ? OFFSET ?";
        $stmt = $pdo->prepare($listSql);
        $stmt->execute(array_merge($params, $orphanParams, [$limit, $offset]));
        $clients = $stmt->fetchAll();
    } else {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM clients c {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $listParams = array_merge($params, [$limit, $offset]);
        $stmt = $pdo->prepare("SELECT {$clientSelectCols} FROM clients c {$whereSql} ORDER BY {$orderBy} LIMIT ? OFFSET ?");
        $stmt->execute($listParams);
        $clients = $stmt->fetchAll();
    }

    foreach ($clients as &$clientRow) {
        $clientRow['envios_sent'] = (float)($clientRow['envios_sent'] ?? 0);
        $clientRow['envios_count'] = (int)($clientRow['envios_count'] ?? 0);
        $clientRow['checks_volume'] = (float)($clientRow['checks_volume'] ?? 0);
        $clientRow['checks_count'] = (int)($clientRow['checks_count'] ?? 0);
        $clientRow['combined_sent'] = (float)($clientRow['combined_sent'] ?? 0);
        $clientRow['total_sent'] = $clientRow['combined_sent'];
        $clientRow['transfer_count'] = $clientRow['envios_count'] + $clientRow['checks_count'];
        $clientRow['is_check_only'] = empty($clientRow['id']) || (($clientRow['row_kind'] ?? '') === 'check_casher');
    }
    unset($clientRow);

    json_response([
        'clients'          => $clients,
        'total'            => $total,
        'page'             => $page,
        'pages'            => (int)ceil(max(1, $total) / $limit),
        'filter'           => $filter,
        'activity'         => $activityMode,
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
    if (!empty($_POST['action']) && in_array($_POST['action'], ['upload_id', 'upload_receiver_id', 'upload_income', 'upload_face'], true)) {
        $act = $_POST['action'];

        if ($act === 'upload_face') {
            if (!client_face_columns_exist($pdo)) {
                json_error('Face photo columns are not migrated yet. Apply 017_client_face.sql.', 503);
            }
            $clientId = (int)($_POST['client_id'] ?? 0);
            auth_require_client_store_access($pdo, $clientId);
            $consent = filter_var($_POST['consent'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (!$consent) {
                json_error('Client consent is required before saving a face photo.', 400);
            }
            if (empty($_FILES['face_file'])) {
                json_error('No photo provided');
            }
            $file = $_FILES['face_file'];
            $mime = (string)($file['type'] ?? '');
            $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
            if (!str_starts_with($mime, 'image/') && !in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                json_error('Face photo must be an image (jpg, png, webp)', 400);
            }
            $descriptor = client_face_parse_descriptor($_POST['descriptor'] ?? null);
            if ($descriptor === null) {
                error_log('[face] upload_face rejected: invalid/missing descriptor client_id=' . $clientId);
                json_error('No face data in upload. Capture again with a clear front-facing Face ID photo.', 400);
            }
            $tmpPath = (string)($file['tmp_name'] ?? '');
            if ($tmpPath === '' || !is_file($tmpPath)) {
                error_log('[face] upload_face rejected: missing tmp file client_id=' . $clientId);
                json_error('No photo provided', 400);
            }
            // Relax empty browser MIME when extension is an image.
            if ($mime === '' || $mime === 'application/octet-stream') {
                $mime = match ($ext) {
                    'png' => 'image/png',
                    'webp' => 'image/webp',
                    default => 'image/jpeg',
                };
                $file['type'] = $mime;
            }
            $thumb = client_face_make_thumb_data_url($tmpPath);
            // ponytail: thumb is the durable profile image; storage is best-effort for full file
            $path = upload_file($file, 'client-faces', false);
            if ($path === false) {
                $path = null;
            }
            if ($thumb === null && ($path === null || $path === '')) {
                error_log('[face] upload_face rejected: no thumb and no storage path client_id=' . $clientId);
                json_error('Could not save Face ID photo. Try a clear JPEG/PNG.', 400);
            }
            $descJson = client_face_encode_descriptor($descriptor);
            if (client_face_thumb_column_exists($pdo)) {
                $pdo->prepare(
                    'UPDATE clients
                     SET face_photo_path = ?,
                         face_photo_thumb = COALESCE(?, face_photo_thumb),
                         face_descriptor = ?,
                         face_consent_at = ' . sql_now() . ',
                         face_enrolled_at = ' . sql_now() . '
                     WHERE id = ?'
                )->execute([$path, $thumb, $descJson, $clientId]);
            } else {
                if ($path === null || $path === '') {
                    json_error('Face ID storage unavailable and thumb column missing. Apply 019_client_face_thumb.sql.', 503);
                }
                $pdo->prepare(
                    'UPDATE clients
                     SET face_photo_path = ?,
                         face_descriptor = ?,
                         face_consent_at = ' . sql_now() . ',
                         face_enrolled_at = ' . sql_now() . '
                     WHERE id = ?'
                )->execute([$path, $descJson, $clientId]);
            }
            client_activity_log(
                $pdo,
                $clientId,
                'face_enrolled',
                $path
                    ? 'Face ID image + descriptor enrolled'
                    : 'Face ID thumb + descriptor enrolled (storage path unavailable)',
                (int)$user['id']
            );
            $display = $thumb ?: ($path ? stored_file_url($path) : '');
            json_response([
                'success'                => true,
                'path'                   => $path,
                'path_url'               => $path ? stored_file_url($path) : '',
                'face_photo_display_url' => $display,
                'face_enrolled'          => true,
                'has_descriptor'         => true,
                'stored_remote'          => is_string($path) && str_starts_with($path, 'storage://'),
            ]);
        }

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
            (float)($data['monthly_limit'] ?? fincen_default_limit()),
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
            (float)($data['monthly_limit'] ?? fincen_default_limit()),
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

    if ($act === 'clear_face') {
        if (!client_face_columns_exist($pdo)) {
            json_error('Face photo columns are not migrated yet.', 503);
        }
        validate_required($data, ['client_id']);
        $clientId = (int)$data['client_id'];
        auth_require_client_store_access($pdo, $clientId);
        $pdo->prepare(
            'UPDATE clients
             SET face_photo_path = NULL, face_descriptor = NULL,
                 face_consent_at = NULL, face_enrolled_at = NULL'
                . (client_face_thumb_column_exists($pdo) ? ', face_photo_thumb = NULL' : '') . '
             WHERE id = ?'
        )->execute([$clientId]);
        client_activity_log($pdo, $clientId, 'face_cleared', 'Face photo removed', (int)$user['id']);
        json_response(['success' => true]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
