USE tienda_el_rey;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'tienda_el_rey' AND TABLE_NAME = 'stores' AND COLUMN_NAME = 'intermex_agency_number');
SET @sql = IF(@col = 0, 'ALTER TABLE stores ADD COLUMN intermex_agency_number VARCHAR(20) DEFAULT NULL AFTER intercambio_agency_number', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Migration complete — intermex_agency_number column added to stores.' AS result;
