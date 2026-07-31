-- Cross-store check shipments to clear company negative balances (Caja)
-- MySQL local

CREATE TABLE IF NOT EXISTS caja_check_shipments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_store_id INT UNSIGNED NOT NULL,
    to_store_id INT UNSIGNED NOT NULL,
    company VARCHAR(100) NOT NULL,
    session_id INT UNSIGNED DEFAULT NULL,
    shipment_date DATE NOT NULL,
    check_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cash_received DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    check_number VARCHAR(80) DEFAULT NULL,
    image_path VARCHAR(500) DEFAULT NULL,
    image_paths_json TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'sent',
    created_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (from_store_id) REFERENCES stores(id),
    FOREIGN KEY (to_store_id) REFERENCES stores(id),
    FOREIGN KEY (session_id) REFERENCES caja_sessions(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_caja_ship_from_date (from_store_id, shipment_date),
    INDEX idx_caja_ship_to_company (to_store_id, company),
    INDEX idx_caja_ship_status (status)
) ENGINE=InnoDB;

-- Optional session field: cash amount received / noted for the drawer day
-- MySQL 8+: use procedural guard (IF NOT EXISTS not always available on ADD COLUMN)
SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'caja_sessions'
      AND COLUMN_NAME = 'cash_received'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE caja_sessions ADD COLUMN cash_received DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER opening_balance',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'caja_check_shipments migration complete' AS result;
