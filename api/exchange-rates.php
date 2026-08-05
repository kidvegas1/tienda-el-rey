<?php

require_once __DIR__ . '/../includes/exchange-rates.php';

$pdo = db();
$method = get_method();

if ($method === 'GET') {
    $adminView = isset($_GET['admin']) && $_GET['admin'] !== '0' && $_GET['admin'] !== '';
    if ($adminView) {
        auth_require_admin();
        $rates = exchange_rates_list($pdo, false);
        json_response([
            'rates'     => $rates,
            'available' => exchange_rates_table_exists($pdo),
            'count'     => count($rates),
        ]);
    }

    // Public marketing feed — published rates only, no auth.
    $rates = exchange_rates_list($pdo, true);
    json_response([
        'rates'       => $rates,
        'count'       => count($rates),
        'disclaimer'  => 'Tipos de cambio de referencia para envíos. Consulta en tienda antes de enviar; pueden variar según compañía (Barri, Viamericas, Ria) y destino.',
        'updated_hint'=> 'Actualizado por la tienda',
    ]);
}

if ($method === 'POST') {
    csrf_verify();
    $user = auth_require_admin();
    $data = get_json_body();
    $action = (string)($data['action'] ?? 'bulk_save');

    if ($action === 'bulk_save') {
        if (!exchange_rates_table_exists($pdo)) {
            json_error('Exchange rates table is not migrated yet. Apply 018_remittance_exchange_rates.sql.', 503);
        }
        $items = $data['rates'] ?? null;
        if (!is_array($items) || $items === []) {
            json_error('rates array is required', 400);
        }
        try {
            $updated = exchange_rates_bulk_save($pdo, $items, (int)($user['id'] ?? 0) ?: null);
        } catch (Throwable $e) {
            json_error($e->getMessage(), 500);
        }
        json_response([
            'success' => true,
            'updated' => $updated,
            'rates'   => exchange_rates_list($pdo, false),
        ]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
