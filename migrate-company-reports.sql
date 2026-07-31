-- Migration: Add Viamericas / Intermex support columns
-- Run this against the tienda_el_rey database to add new columns

USE tienda_el_rey;

-- Add report_type to barri_reports (if not exists)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'tienda_el_rey' AND TABLE_NAME = 'barri_reports' AND COLUMN_NAME = 'report_type');
SET @sql = IF(@col_exists = 0, "ALTER TABLE barri_reports ADD COLUMN report_type VARCHAR(30) DEFAULT 'barri' AFTER status", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add total_received_foreign to barri_reports
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'tienda_el_rey' AND TABLE_NAME = 'barri_reports' AND COLUMN_NAME = 'total_received_foreign');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE barri_reports ADD COLUMN total_received_foreign DECIMAL(14,2) DEFAULT 0.00 AFTER total_agcomm', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add new columns to barri_transactions
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'tienda_el_rey' AND TABLE_NAME = 'barri_transactions' AND COLUMN_NAME = 'amount_received');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE barri_transactions ADD COLUMN amount_received DECIMAL(14,2) DEFAULT 0.00 AFTER total', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'tienda_el_rey' AND TABLE_NAME = 'barri_transactions' AND COLUMN_NAME = 'received_currency');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE barri_transactions ADD COLUMN received_currency VARCHAR(10) DEFAULT NULL AFTER amount_received', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'tienda_el_rey' AND TABLE_NAME = 'barri_transactions' AND COLUMN_NAME = 'paying_bank');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE barri_transactions ADD COLUMN paying_bank VARCHAR(200) DEFAULT NULL AFTER received_currency', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'tienda_el_rey' AND TABLE_NAME = 'barri_transactions' AND COLUMN_NAME = 'destination_country');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE barri_transactions ADD COLUMN destination_country VARCHAR(100) DEFAULT NULL AFTER paying_bank', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'tienda_el_rey' AND TABLE_NAME = 'barri_transactions' AND COLUMN_NAME = 'destination_state');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE barri_transactions ADD COLUMN destination_state VARCHAR(100) DEFAULT NULL AFTER destination_country', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'tienda_el_rey' AND TABLE_NAME = 'barri_transactions' AND COLUMN_NAME = 'destination_city');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE barri_transactions ADD COLUMN destination_city VARCHAR(200) DEFAULT NULL AFTER destination_state', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'tienda_el_rey' AND TABLE_NAME = 'barri_transactions' AND COLUMN_NAME = 'payment_date');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE barri_transactions ADD COLUMN payment_date DATETIME DEFAULT NULL AFTER destination_city', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'tienda_el_rey' AND TABLE_NAME = 'barri_transactions' AND COLUMN_NAME = 'transaction_status');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE barri_transactions ADD COLUMN transaction_status VARCHAR(50) DEFAULT NULL AFTER payment_date', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Migration complete — company reports columns added.' AS result;
