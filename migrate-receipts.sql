-- Receipts / business expenses (MySQL)
-- Run: mysql -u root tienda_el_rey < migrate-receipts.sql

CREATE TABLE IF NOT EXISTS receipts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    image_path VARCHAR(500) DEFAULT NULL,
    vendor VARCHAR(200) DEFAULT NULL,
    receipt_date DATE DEFAULT NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    category VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    ai_raw_json JSON DEFAULT NULL,
    accounting_entry_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (accounting_entry_id) REFERENCES accounting_entries(id) ON DELETE SET NULL,
    INDEX idx_receipts_store_date (store_id, receipt_date),
    INDEX idx_receipts_store_status (store_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS receipt_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_id INT UNSIGNED NOT NULL,
    description VARCHAR(500) NOT NULL,
    quantity DECIMAL(10,3) NOT NULL DEFAULT 1.000,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    editable TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE CASCADE,
    INDEX idx_receipt_items_receipt (receipt_id)
) ENGINE=InnoDB;

-- CREATE INDEX idx_inventory_movements_store_type_created ON inventory_movements (store_id, movement_type, created_at);

SELECT 'receipts migration complete' AS result;
