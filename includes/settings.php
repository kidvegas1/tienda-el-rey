<?php

function app_setting(string $key, string $default = ''): string {
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $value = $row ? (string)$row['setting_value'] : $default;
    } catch (\Throwable $e) {
        $value = $default;
    }

    $cache[$key] = $value;
    return $value;
}

function app_setting_float(string $key, float $default = 0.0): float {
    $value = app_setting($key, (string)$default);
    return is_numeric($value) ? (float)$value : $default;
}

function app_setting_set(string $key, string $value): void {
    $pdo = db();
    $stmt = $pdo->prepare(sql_upsert('app_settings', ['setting_key', 'setting_value'], ['setting_value'], ['setting_key']));
    $stmt->execute([$key, $value]);
}

function inventory_global_tax_rate(): float {
    $default = defined('DEFAULT_TAX_RATE') ? DEFAULT_TAX_RATE : 8.25;
    $raw = app_setting('inventory.global_tax_rate', '');
    if ($raw === '') {
        $raw = app_setting('global_tax_rate', (string) $default);
    }
    $rate = is_numeric($raw) ? (float) $raw : $default;
    return $rate >= 0 && $rate <= 100 ? $rate : $default;
}

function inventory_tax_label(): string {
    $label = app_setting('inventory.tax_label', '');
    if ($label === '') {
        $label = app_setting('tax_label', 'Texas Sales Tax');
    }
    return $label !== '' ? $label : 'Sales Tax';
}

function inventory_set_tax_settings(float $rate, string $label): void {
    if ($rate < 0 || $rate > 100) {
        throw new InvalidArgumentException('Tax rate must be between 0 and 100.');
    }

    $label = trim($label) ?: 'Sales Tax';
    // Keep both key styles in sync (migration used unprefixed keys).
    app_setting_set('inventory.global_tax_rate', (string) $rate);
    app_setting_set('global_tax_rate', (string) $rate);
    app_setting_set('inventory.tax_label', $label);
    app_setting_set('tax_label', $label);
}
