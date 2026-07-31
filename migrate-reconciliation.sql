-- Reconciliation variances + ledger provenance (MySQL)
-- Run: mysql -u root tienda_el_rey < migrate-reconciliation.sql

CREATE TABLE IF NOT EXISTS reconciliation_variances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    company VARCHAR(50) NOT NULL,
    variance_date DATE NOT NULL,
    metric VARCHAR(30) NOT NULL DEFAULT 'daily_total',
    excel_amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
    report_amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
    diff_amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
    excel_import_id INT UNSIGNED DEFAULT NULL,
    barri_report_id INT UNSIGNED DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    reviewed_by_user_id INT UNSIGNED DEFAULT NULL,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    FOREIGN KEY (excel_import_id) REFERENCES excel_imports(id) ON DELETE SET NULL,
    FOREIGN KEY (barri_report_id) REFERENCES barri_reports(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_recon_variances_store_date (store_id, variance_date),
    INDEX idx_recon_variances_status (status),
    UNIQUE KEY idx_recon_variances_unique (store_id, company, variance_date, metric)
) ENGINE=InnoDB;

-- Ledger provenance columns (skip if already present)
-- ALTER TABLE internal_ledger ADD COLUMN company VARCHAR(50) DEFAULT NULL;
-- ALTER TABLE internal_ledger ADD COLUMN entry_source VARCHAR(20) NOT NULL DEFAULT 'manual';
-- ALTER TABLE internal_ledger ADD COLUMN source_ref VARCHAR(100) DEFAULT NULL;
-- ALTER TABLE internal_ledger ADD COLUMN barri_transaction_id INT UNSIGNED DEFAULT NULL;
-- CREATE INDEX idx_internal_ledger_entry_source ON internal_ledger (entry_source);
-- CREATE INDEX idx_internal_ledger_source_ref ON internal_ledger (source_ref);

SELECT 'reconciliation_variances ready' AS result;
