-- Retail inventory expansion for MySQL 8.x.
-- Safe to run repeatedly against an existing tienda_el_rey database.
USE tienda_el_rey;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(500) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

DROP PROCEDURE IF EXISTS migrate_inventory_retail;
DELIMITER //

CREATE PROCEDURE migrate_inventory_retail()
BEGIN
    DECLARE column_exists INT DEFAULT 0;
    DECLARE index_exists INT DEFAULT 0;
    DECLARE description_type VARCHAR(64) DEFAULT NULL;

    SELECT COUNT(*) INTO column_exists
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory' AND column_name = 'barcode';
    IF column_exists = 0 THEN
        ALTER TABLE inventory ADD COLUMN barcode VARCHAR(64) NULL AFTER quantity;
    END IF;

    SELECT COUNT(*) INTO column_exists
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory' AND column_name = 'sku';
    IF column_exists = 0 THEN
        ALTER TABLE inventory ADD COLUMN sku VARCHAR(64) NULL AFTER barcode;
    END IF;

    SELECT COUNT(*) INTO column_exists
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory' AND column_name = 'category';
    IF column_exists = 0 THEN
        ALTER TABLE inventory ADD COLUMN category VARCHAR(100) NULL AFTER sku;
    END IF;

    SELECT COUNT(*) INTO column_exists
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory' AND column_name = 'description';
    IF column_exists = 0 THEN
        ALTER TABLE inventory ADD COLUMN description TEXT NULL AFTER category;
    ELSE
        SELECT data_type INTO description_type
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'inventory' AND column_name = 'description';
        IF description_type <> 'text' THEN
        ALTER TABLE inventory MODIFY COLUMN description TEXT NULL;
        END IF;
    END IF;

    SELECT COUNT(*) INTO column_exists
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory' AND column_name = 'image_path';
    IF column_exists = 0 THEN
        ALTER TABLE inventory ADD COLUMN image_path VARCHAR(500) NULL AFTER description;
    END IF;

    SELECT COUNT(*) INTO column_exists
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory' AND column_name = 'image_paths_json';
    IF column_exists = 0 THEN
        ALTER TABLE inventory ADD COLUMN image_paths_json TEXT NULL AFTER image_path;
    END IF;

    SELECT COUNT(*) INTO column_exists
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory' AND column_name = 'taxable';
    IF column_exists = 0 THEN
        ALTER TABLE inventory ADD COLUMN taxable TINYINT(1) NOT NULL DEFAULT 1 AFTER retail_price;
    END IF;

    SELECT COUNT(*) INTO column_exists
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory' AND column_name = 'active';
    IF column_exists = 0 THEN
        ALTER TABLE inventory ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER taxable;
    END IF;

    SELECT COUNT(*) INTO index_exists
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'inventory'
      AND index_name = 'uk_inventory_store_barcode';
    IF index_exists = 0 THEN
        ALTER TABLE inventory
            ADD UNIQUE KEY uk_inventory_store_barcode (store_id, barcode);
    END IF;
END//

DELIMITER ;
CALL migrate_inventory_retail();
DROP PROCEDURE migrate_inventory_retail;

CREATE TABLE IF NOT EXISTS inventory_movements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    inventory_id INT UNSIGNED NOT NULL,
    movement_type ENUM('stock_in','sale','adjust') NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) DEFAULT NULL,
    tax_rate DECIMAL(6,3) DEFAULT NULL,
    tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    barcode VARCHAR(64) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inventory_movements_store
        FOREIGN KEY (store_id) REFERENCES stores(id),
    CONSTRAINT fk_inventory_movements_inventory
        FOREIGN KEY (inventory_id) REFERENCES inventory(id),
    CONSTRAINT fk_inventory_movements_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_inventory_movements_inventory_created (inventory_id, created_at),
    INDEX idx_inventory_movements_store_created (store_id, created_at),
    INDEX idx_inventory_movements_barcode (store_id, barcode)
) ENGINE=InnoDB;

INSERT INTO app_settings (setting_key, setting_value)
VALUES ('global_tax_rate', '8.25')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO app_settings (setting_key, setting_value)
VALUES ('tax_label', 'Texas Sales Tax')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

SELECT 'inventory retail migration complete' AS result;
