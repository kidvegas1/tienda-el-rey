USE tienda_el_rey;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'tienda_el_rey' AND TABLE_NAME = 'stores' AND COLUMN_NAME = 'viamericas_agency_number');
SET @sql = IF(@col = 0, 'ALTER TABLE stores ADD COLUMN viamericas_agency_number VARCHAR(20) DEFAULT NULL AFTER barri_operator_number', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'tienda_el_rey' AND TABLE_NAME = 'stores' AND COLUMN_NAME = 'intercambio_agency_number');
SET @sql = IF(@col = 0, 'ALTER TABLE stores ADD COLUMN intercambio_agency_number VARCHAR(20) DEFAULT NULL AFTER viamericas_agency_number', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Migration complete — store agency number columns added.' AS result;
