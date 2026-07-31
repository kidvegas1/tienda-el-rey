<?php

function csrf_token(): string {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function csrf_verify(): bool {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST[CSRF_TOKEN_NAME] ?? '';
    if (empty($token) || !hash_equals($_SESSION[CSRF_TOKEN_NAME] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
    return true;
}

function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function sanitize_array(array $data, array $keys): array {
    $clean = [];
    foreach ($keys as $key) {
        $clean[$key] = isset($data[$key]) ? sanitize((string)$data[$key]) : '';
    }
    return $clean;
}

function json_response(mixed $data, int $code = 200): never {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $code = 400): never {
    json_response(['error' => $message], $code);
}

function validate_required(array $data, array $fields): void {
    foreach ($fields as $field) {
        if (empty($data[$field]) && $data[$field] !== '0' && $data[$field] !== 0) {
            json_error("Field '{$field}' is required.");
        }
    }
}

function get_json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error('Invalid JSON body.');
    }
    return $data;
}

function get_method(): string {
    return $_SERVER['REQUEST_METHOD'];
}
