<?php

/** Canonical company keys used in reconciliation. */
function company_canonical_map(): array {
    return [
        'barri'        => 'Barri',
        'via'          => 'Viamericas',
        'viamericas'   => 'Viamericas',
        'inter'        => 'Intercambio',
        'intercambio'  => 'Intercambio',
        'intermex'     => 'Intermex',
        'ria'          => 'Ria',
        'dinex'        => 'Dinex',
        'jp cheques'   => 'JP Cheques',
        'jp'           => 'JP Cheques',
        'lake june'    => 'Lake June',
    ];
}

/** Normalize free-text company name to a canonical display label. */
function company_normalize(?string $name): string {
    $raw = trim((string)$name);
    if ($raw === '') {
        return 'Other';
    }

    $key = strtolower(preg_replace('/\s+/', ' ', $raw));
    $map = company_canonical_map();

    if (isset($map[$key])) {
        return $map[$key];
    }

    foreach ($map as $needle => $label) {
        if (str_contains($key, $needle)) {
            return $label;
        }
    }

    return $raw;
}

/** SQL expression fragment matching normalized company (parameterized via LIKE list). */
function company_normalize_key(?string $name): string {
    return strtolower(preg_replace('/[^a-z0-9]+/i', '', company_normalize($name)));
}
