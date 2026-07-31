-- Inventory retail API settings sync (MySQL).
-- Schema for barcode/images/tax/movements lives in migrate-inventory-retail.sql.
-- This file only syncs tax setting keys used by includes/settings.php.
-- Safe to re-run.

USE tienda_el_rey;

INSERT INTO app_settings (setting_key, setting_value)
VALUES
    ('global_tax_rate', '8.25'),
    ('tax_label', 'Texas Sales Tax'),
    ('inventory.global_tax_rate', '8.25'),
    ('inventory.tax_label', 'Texas Sales Tax')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
