<?php
require_once __DIR__ . '/../includes/gemini.php';
$out = gemini_sanitize_receipt([
    'vendor' => 'Home Depot',
    'date' => '07/15/2026',
    'subtotal' => 100,
    'tax' => 8.25,
    'total' => 108.25,
    'category_suggestion' => 'Supplies',
    'line_items' => [
        ['description' => 'Paint', 'qty' => 2, 'amount' => 50],
        ['description' => 'Brushes', 'qty' => 1, 'amount' => 50],
    ],
]);
assert($out['date'] === '2026-07-15', 'date normalize');
assert($out['tax'] === 8.25, 'tax');
assert(count($out['line_items']) === 2, 'lines');
echo "gemini_sanitize_receipt OK\n";
