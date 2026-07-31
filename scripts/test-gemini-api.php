#!/usr/bin/env php
<?php
/**
 * Live Gemini API smoke test (requires GEMINI_API_KEY in .env).
 * Run: php scripts/test-gemini-api.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/gemini.php';

$failures = 0;
$fail = static function (string $msg) use (&$failures): void {
    echo "FAIL: {$msg}\n";
    $failures++;
};

if (!gemini_configured()) {
    echo "FAIL: GEMINI_API_KEY is not set in .env\n";
    exit(1);
}

$model = GEMINI_MODEL ?: 'gemini-2.5-flash';
echo "== Gemini API live test ==\n";
echo "Model: {$model}\n";

$url = 'https://generativelanguage.googleapis.com/v1beta/models/'
    . rawurlencode($model)
    . ':generateContent?key=' . rawurlencode(GEMINI_API_KEY);

$call = static function (array $payload, int $timeout = 60) use ($url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    if ($raw === false) {
        throw new RuntimeException($err ?: 'curl failed');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON from Gemini (HTTP ' . $code . ')');
    }
    if ($code >= 400) {
        throw new RuntimeException($decoded['error']['message'] ?? ('HTTP ' . $code));
    }
    return $decoded;
};

try {
    $started = microtime(true);
    $resp = $call([
        'contents' => [['parts' => [['text' => 'Reply with exactly: OK']]]],
        'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 16],
    ], 30);
    $text = gemini_extract_text($resp);
    $elapsed = round(microtime(true) - $started, 2);
    if (stripos($text, 'OK') === false) {
        $fail('smoke test expected OK, got: ' . ($text ?: '(empty)'));
    } else {
        echo "Smoke: OK ({$elapsed}s)\n";
    }
} catch (Throwable $e) {
    $fail('smoke test: ' . $e->getMessage());
}

$csv = <<<'CSV'
Agencia,A22592
Desde,04/01/2026
Hasta,04/30/2026
Referencia,Cliente,Beneficiario,Estado,Principal,Fee,Total
A22592-12866,EVELIO IXTOS TEPAZ,BERLY GUARCHAJ TZEP,SULY2022 Pagado,100.00,8.00,109.00
A22592-12892,ERWIN CHOC XOL,MARIA RAX TUIL,SULY2022 Cancelado,1698.01,20.00,1735.00
CSV;

try {
    $started = microtime(true);
    $resp = $call([
        'contents' => [[
            'parts' => [[
                'text' => gemini_remittance_prompt('sample.csv') . "\n\nDocument text:\n" . $csv,
            ]],
        ]],
        'generationConfig' => [
            'temperature' => 0.1,
            'responseMimeType' => 'application/json',
        ],
    ], 90);
    $text = gemini_extract_text($resp);
    if ($text === '') {
        throw new RuntimeException('empty JSON response');
    }
    $parsed = gemini_sanitize_parsed_report(json_decode($text, true) ?: []);
    $elapsed = round(microtime(true) - $started, 2);
    $txns = count($parsed['transactions']);
    echo "CSV parse: agency={$parsed['agency_number']} operator={$parsed['operator_number']} txns={$txns} ({$elapsed}s)\n";
    if ($parsed['agency_number'] !== 'A22592') {
        $fail('expected agency A22592');
    }
    if ($txns < 2) {
        $fail('expected at least 2 transactions from CSV sample');
    }
} catch (Throwable $e) {
    $fail('CSV parse: ' . $e->getMessage());
}

if ($failures > 0) {
    echo "FAILED: {$failures} check(s)\n";
    exit(1);
}

echo "OK: Gemini API live test passed\n";
exit(0);
