<?php
/**
 * Company verification — lookup flags + check-photo AI parse before cashing.
 */
$user = auth_require();
$method = get_method();
$pdo = db();
require_once __DIR__ . '/../includes/company-flags.php';
require_once __DIR__ . '/../includes/gemini.php';

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'verify';

    if ($action === 'list_flags') {
        json_response([
            'flags' => company_flags_list_active($pdo),
            'can_manage' => auth_is_admin(),
        ]);
    }

    $q = trim((string)($_GET['q'] ?? $_GET['company'] ?? ''));
    if ($q === '') {
        json_error('Company name is required (q)', 400);
    }
    if (mb_strlen($q) > 120) {
        json_error('Company name is too long', 400);
    }

    json_response(company_verification_payload($pdo, $q));
}

if ($method === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? null;
    $data = [];
    if ($action === null) {
        $data = get_json_body();
        $action = $data['action'] ?? '';
    }

    if ($action === 'parse_check') {
        if (!gemini_configured()) {
            json_error('AI check parser is not configured. Set GEMINI_API_KEY in server environment.', 503);
        }
        $file = $_FILES['file'] ?? $_FILES['image'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            json_error('A check photo is required.', 422);
        }
        if (($file['size'] ?? 0) > MAX_UPLOAD_SIZE) {
            json_error('File exceeds maximum upload size.', 400);
        }
        $filename = basename($file['name'] ?? 'check.jpg');
        $mime = gemini_mime_for_filename($filename) ?? 'image/jpeg';
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
        if ($finfo) finfo_close($finfo);
        if (is_string($detected) && in_array($detected, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $mime = $detected;
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            json_error('Unsupported file type. Use JPG, PNG, or WEBP.', 400);
        }

        try {
            $parsed = gemini_parse_check($file['tmp_name'], $mime, $filename);
        } catch (Throwable $e) {
            error_log('company-verification parse: ' . $e->getMessage());
            json_error('Check parsing failed. Try a clearer photo.', 502);
        }

        $company = trim((string)($parsed['company'] ?? ''));
        if ($company === '') {
            json_response([
                'parsed' => $parsed,
                'is_flagged' => false,
                'flag' => null,
                'similar_flags' => [],
                'history' => company_verification_history($pdo, ''),
                'recommendation' => 'unknown',
                'query' => '',
                'company_key' => '',
                'can_manage_flags' => auth_is_admin(),
                'warning' => 'Could not read a company name from the check.',
            ]);
        }

        json_response(company_verification_payload($pdo, $company, $parsed));
    }

    if ($action === 'set_flag') {
        company_flag_require_admin();
        validate_required($data, ['company', 'reason']);
        $flag = company_flag_set($pdo, (string)$data['company'], (string)$data['reason'], (int)$user['id']);
        json_response(['success' => true, 'flag' => $flag]);
    }

    if ($action === 'clear_flag') {
        company_flag_require_admin();
        validate_required($data, ['company']);
        company_flag_clear($pdo, (string)$data['company'], (int)$user['id']);
        json_response(['success' => true]);
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
