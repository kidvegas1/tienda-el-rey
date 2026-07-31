-- Tienda Hispana El Rey Dashboard - Database Schema
-- MySQL 8.x

CREATE DATABASE IF NOT EXISTS tienda_el_rey CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tienda_el_rey;

-- ── Stores ──
CREATE TABLE stores (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    address VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    barri_agency_number VARCHAR(20) DEFAULT NULL,
    barri_operator_number VARCHAR(20) DEFAULT NULL,
    viamericas_agency_number VARCHAR(20) DEFAULT NULL,
    intercambio_agency_number VARCHAR(20) DEFAULT NULL,
    intermex_agency_number VARCHAR(20) DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    publish_inventory TINYINT(1) NOT NULL DEFAULT 1,
    publish_prices TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO stores (name) VALUES ('Bruton'), ('Carrollton'), ('Lake June');

-- ── Users ──
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED DEFAULT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','manager','cashier','employee') NOT NULL DEFAULT 'employee',
    photo_url VARCHAR(500) DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- No administrator credentials are seeded.
-- Create the first administrator with: php scripts/bootstrap-admin.php

-- ── Caja Sessions ──
CREATE TABLE caja_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    session_date DATE NOT NULL,
    cashier_name VARCHAR(100) DEFAULT NULL,
    opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cash_received DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    closing_balance DECIMAL(12,2) DEFAULT NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_store_date (store_id, session_date)
) ENGINE=InnoDB;

-- ── Caja Entries ──
CREATE TABLE caja_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    company VARCHAR(100) NOT NULL,
    cash_in DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    checks_debits DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(12,2) GENERATED ALWAYS AS (cash_in + checks_debits) STORED,
    notes VARCHAR(500) DEFAULT NULL,
    sort_order SMALLINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES caja_sessions(id) ON DELETE CASCADE,
    INDEX idx_session (session_id)
) ENGINE=InnoDB;

-- ── Caja Denominations ──
CREATE TABLE caja_denominations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    denomination DECIMAL(6,2) NOT NULL,
    count INT NOT NULL DEFAULT 0,
    subtotal DECIMAL(12,2) GENERATED ALWAYS AS (denomination * count) STORED,
    FOREIGN KEY (session_id) REFERENCES caja_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Checks shipped to another store to clear that store's negative company balance
CREATE TABLE caja_check_shipments (
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

-- ── Company Flags (manual admin risk markers; global, not session-scoped) ──
CREATE TABLE company_flags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_key VARCHAR(120) NOT NULL,
    company_label VARCHAR(120) NOT NULL,
    reason TEXT NOT NULL,
    flagged_by_user_id INT UNSIGNED DEFAULT NULL,
    flagged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    cleared_at TIMESTAMP NULL DEFAULT NULL,
    cleared_by_user_id INT UNSIGNED DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (flagged_by_user_id) REFERENCES users(id),
    FOREIGN KEY (cleared_by_user_id) REFERENCES users(id),
    INDEX idx_company_flags_key (company_key),
    INDEX idx_company_flags_active (is_active)
) ENGINE=InnoDB;

-- ── Clients ──
CREATE TABLE clients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_code VARCHAR(30) DEFAULT NULL,
    name VARCHAR(200) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    monthly_limit DECIMAL(10,2) NOT NULL DEFAULT 3000.00,
    income_verified TINYINT(1) NOT NULL DEFAULT 0,
    income_doc_path VARCHAR(500) DEFAULT NULL,
    sender_id_path VARCHAR(500) DEFAULT NULL,
    sender_id_type VARCHAR(50) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_code (client_code)
) ENGINE=InnoDB;

-- ── Transfers ──
CREATE TABLE transfers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    receiver_id INT UNSIGNED DEFAULT NULL,
    store_id INT UNSIGNED NOT NULL,
    transaction_code VARCHAR(30) DEFAULT NULL,
    beneficiary VARCHAR(200) NOT NULL,
    date_sent DATETIME NOT NULL,
    date_paid DATETIME DEFAULT NULL,
    amount_usd DECIMAL(12,2) NOT NULL,
    fee DECIMAL(10,2) DEFAULT NULL,
    tax DECIMAL(10,2) DEFAULT NULL,
    amount_local DECIMAL(14,2) DEFAULT NULL,
    currency VARCHAR(10) DEFAULT 'MXN',
    paying_bank VARCHAR(100) DEFAULT NULL,
    destination_country VARCHAR(100) DEFAULT NULL,
    destination_city VARCHAR(200) DEFAULT NULL,
    company VARCHAR(50) DEFAULT NULL,
    transaction_type VARCHAR(30) DEFAULT NULL,
    source VARCHAR(30) DEFAULT 'manual',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (store_id) REFERENCES stores(id),
    INDEX idx_client_date (client_id, date_sent),
    INDEX idx_store_date (store_id, date_sent)
) ENGINE=InnoDB;

-- ── Client Activity Log ──
CREATE TABLE client_activity_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    store_id INT UNSIGNED DEFAULT NULL,
    actor_user_id INT UNSIGNED DEFAULT NULL,
    event_type VARCHAR(50) NOT NULL,
    summary VARCHAR(500) NOT NULL DEFAULT '',
    payload TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    FOREIGN KEY (actor_user_id) REFERENCES users(id),
    INDEX idx_client_activity_client (client_id),
    INDEX idx_client_activity_created (created_at),
    INDEX idx_client_activity_event (event_type)
) ENGINE=InnoDB;

-- ── Client Record Requests ──
CREATE TABLE client_record_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requester_name VARCHAR(200) NOT NULL,
    requester_phone VARCHAR(30) DEFAULT NULL,
    matched_client_id INT UNSIGNED DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    date_from DATE DEFAULT NULL,
    date_to DATE DEFAULT NULL,
    fulfilled_by_user_id INT UNSIGNED DEFAULT NULL,
    fulfillment_notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fulfilled_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (matched_client_id) REFERENCES clients(id),
    FOREIGN KEY (fulfilled_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ── Transfer Security Alerts ──
CREATE TABLE transfer_security_alerts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alert_type VARCHAR(50) NOT NULL,
    severity VARCHAR(20) NOT NULL DEFAULT 'medium',
    client_id INT UNSIGNED DEFAULT NULL,
    store_id INT UNSIGNED DEFAULT NULL,
    transfer_id INT UNSIGNED DEFAULT NULL,
    title VARCHAR(200) NOT NULL,
    details TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    detected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    resolved_by_user_id INT UNSIGNED DEFAULT NULL,
    resolution_notes TEXT DEFAULT NULL,
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (store_id) REFERENCES stores(id),
    FOREIGN KEY (transfer_id) REFERENCES transfers(id),
    FOREIGN KEY (resolved_by_user_id) REFERENCES users(id),
    INDEX idx_security_alerts_status (status),
    INDEX idx_security_alerts_type (alert_type),
    INDEX idx_security_alerts_client (client_id),
    INDEX idx_security_alerts_detected (detected_at)
) ENGINE=InnoDB;

-- ── Libro interno (internal ledger) ──
CREATE TABLE internal_ledger (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    employee_name VARCHAR(100) DEFAULT NULL,
    description VARCHAR(500) NOT NULL,
    owed_to_store DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    store_owes DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    entry_date DATE NOT NULL,
    notes TEXT DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    paid_at TIMESTAMP NULL DEFAULT NULL,
    paid_by_user_id INT UNSIGNED DEFAULT NULL,
    company VARCHAR(50) DEFAULT NULL,
    entry_source VARCHAR(20) NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(100) DEFAULT NULL,
    barri_transaction_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    FOREIGN KEY (paid_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_store_date (store_id, entry_date),
    INDEX idx_status (status),
    INDEX idx_internal_ledger_entry_source (entry_source),
    INDEX idx_internal_ledger_source_ref (source_ref)
) ENGINE=InnoDB;

-- ── Employees ──
CREATE TABLE employees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    name VARCHAR(100) NOT NULL,
    hourly_rate DECIMAL(8,2) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── Clock Ins ──
CREATE TABLE clock_ins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    store_id INT UNSIGNED NOT NULL,
    clock_in_time DATETIME NOT NULL,
    clock_out_time DATETIME DEFAULT NULL,
    photo_path VARCHAR(500) NOT NULL,
    clock_out_photo_path VARCHAR(500) DEFAULT NULL,
    hours_worked DECIMAL(5,2) DEFAULT NULL,
    status ENUM('clocked_in','clocked_out','missed') NOT NULL DEFAULT 'clocked_in',
    notes VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    FOREIGN KEY (store_id) REFERENCES stores(id),
    INDEX idx_employee_date (employee_id, clock_in_time),
    INDEX idx_store_date (store_id, clock_in_time)
) ENGINE=InnoDB;

-- ── Schedules ──
CREATE TABLE schedules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    week_start DATE NOT NULL,
    day_of_week TINYINT NOT NULL COMMENT '0=Mon,6=Sun',
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    INDEX idx_store_week (store_id, week_start)
) ENGINE=InnoDB;

-- ── Transfer Statistics ──
CREATE TABLE transfer_statistics (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    company VARCHAR(50) NOT NULL,
    month TINYINT NOT NULL,
    year SMALLINT NOT NULL,
    transfer_count INT NOT NULL DEFAULT 0,
    total_usd DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    UNIQUE KEY uk_store_company_period (store_id, company, month, year)
) ENGINE=InnoDB;

-- ── Accounting Entries ──
CREATE TABLE accounting_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    category VARCHAR(100) NOT NULL,
    description VARCHAR(500) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    entry_type ENUM('receivable','payable') NOT NULL,
    entry_date DATE NOT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    INDEX idx_store_cat (store_id, category)
) ENGINE=InnoDB;

-- ── Inventory (Medicine) ──
CREATE TABLE inventory (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(200) NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    barcode VARCHAR(64) DEFAULT NULL,
    sku VARCHAR(64) DEFAULT NULL,
    category VARCHAR(100) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    image_path VARCHAR(500) DEFAULT NULL,
    image_paths_json TEXT DEFAULT NULL,
    cost_price DECIMAL(10,2) DEFAULT NULL,
    retail_price DECIMAL(10,2) DEFAULT NULL,
    taxable TINYINT(1) NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    low_stock_threshold INT NOT NULL DEFAULT 5,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    INDEX idx_store_product (store_id, product_name),
    UNIQUE KEY uk_inventory_store_barcode (store_id, barcode)
) ENGINE=InnoDB;

-- ── Inventory Movements ──
CREATE TABLE inventory_movements (
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
    FOREIGN KEY (store_id) REFERENCES stores(id),
    FOREIGN KEY (inventory_id) REFERENCES inventory(id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_inventory_movements_inventory_created (inventory_id, created_at),
    INDEX idx_inventory_movements_store_created (store_id, created_at),
    INDEX idx_inventory_movements_barcode (store_id, barcode)
) ENGINE=InnoDB;

-- ── Application Settings ──
CREATE TABLE app_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(500) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('fincen_global_limit', '3000'),
    ('fincen_period', 'month'),
    ('global_tax_rate', '8.25'),
    ('tax_label', 'Texas Sales Tax');

-- ── Events (Salon de Eventos) ──
CREATE TABLE events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    client_name VARCHAR(200) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    event_date DATE NOT NULL,
    deposit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) GENERATED ALWAYS AS (deposit + balance) STORED,
    color_theme VARCHAR(100) DEFAULT NULL,
    package VARCHAR(100) DEFAULT NULL,
    payment_method VARCHAR(100) DEFAULT NULL,
    status ENUM('booked','confirmed','completed','cancelled') NOT NULL DEFAULT 'booked',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    INDEX idx_store_date (store_id, event_date)
) ENGINE=InnoDB;

-- ── Plates (Vehicle Registration) ──
CREATE TABLE plates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    client_name VARCHAR(200) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    vin VARCHAR(50) DEFAULT NULL,
    service_type VARCHAR(100) NOT NULL,
    delivery_date DATE DEFAULT NULL,
    payment DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    agent_name VARCHAR(100) DEFAULT NULL,
    agent_fee DECIMAL(10,2) DEFAULT NULL,
    status ENUM('pending','in_progress','completed','delivered') NOT NULL DEFAULT 'pending',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB;

-- ── Secure Notes ──
CREATE TABLE secure_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB;

-- ── Excel Imports ──
CREATE TABLE excel_imports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    sheet_mapping JSON DEFAULT NULL,
    rows_imported INT DEFAULT 0,
    status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    errors TEXT DEFAULT NULL,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ── Barri Reports ──
CREATE TABLE barri_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    agency_number VARCHAR(20) DEFAULT NULL,
    agency_name VARCHAR(200) DEFAULT NULL,
    agency_address VARCHAR(300) DEFAULT NULL,
    company VARCHAR(50) DEFAULT 'Barri',
    store_name VARCHAR(200) DEFAULT NULL,
    ar_executive VARCHAR(200) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    report_date_from DATE NOT NULL,
    report_date_to DATE NOT NULL,
    beginning_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    ending_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_transactions INT NOT NULL DEFAULT 0,
    total_principal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_fees DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_agcomm DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    filename VARCHAR(255) NOT NULL DEFAULT '',
    original_name VARCHAR(255) NOT NULL DEFAULT '',
    status ENUM('pending','processed','error') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_store_date (store_id, report_date_from)
) ENGINE=InnoDB;

-- ── Reconciliation variances (Excel vs report) ──
CREATE TABLE reconciliation_variances (
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

-- ── Barri Transactions ──
CREATE TABLE barri_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id INT UNSIGNED NOT NULL,
    store_id INT UNSIGNED NOT NULL,
    client_id INT UNSIGNED DEFAULT NULL,
    transaction_time TIME NOT NULL,
    transaction_date DATE NOT NULL,
    transaction_type VARCHAR(30) NOT NULL DEFAULT 'giros',
    reference_number VARCHAR(30) NOT NULL,
    customer_name VARCHAR(200) NOT NULL,
    beneficiary_name VARCHAR(200) DEFAULT NULL,
    description VARCHAR(300) DEFAULT NULL,
    operator VARCHAR(30) DEFAULT NULL,
    quantity INT NOT NULL DEFAULT 1,
    principal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    running_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    ag_commission DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    variable_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    variable_fx DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    matched TINYINT(1) NOT NULL DEFAULT 0,
    pushed_to_transfers TINYINT(1) NOT NULL DEFAULT 0,
    transfer_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (report_id) REFERENCES barri_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    FOREIGN KEY (transfer_id) REFERENCES transfers(id) ON DELETE SET NULL,
    INDEX idx_report (report_id),
    INDEX idx_client (client_id),
    INDEX idx_reference (reference_number)
) ENGINE=InnoDB;

-- ── Receivers (beneficiaries with ID for disambiguation) ──
CREATE TABLE receivers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    id_path VARCHAR(500) DEFAULT NULL,
    id_type VARCHAR(50) DEFAULT NULL,
    destination_country VARCHAR(100) DEFAULT NULL,
    destination_city VARCHAR(200) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_client_name (client_id, name)
) ENGINE=InnoDB;

-- ── ALTER: Add sender ID columns to clients ──
-- ALTER TABLE clients ADD COLUMN sender_id_path VARCHAR(500) DEFAULT NULL AFTER income_doc_path;
-- ALTER TABLE clients ADD COLUMN sender_id_type VARCHAR(50) DEFAULT NULL AFTER sender_id_path;

-- ── ALTER: Add fee/tax/source columns to transfers ──
-- ALTER TABLE transfers ADD COLUMN receiver_id INT UNSIGNED DEFAULT NULL AFTER client_id;
-- ALTER TABLE transfers ADD COLUMN fee DECIMAL(10,2) DEFAULT NULL AFTER amount_usd;
-- ALTER TABLE transfers ADD COLUMN tax DECIMAL(10,2) DEFAULT NULL AFTER fee;
-- ALTER TABLE transfers ADD COLUMN transaction_type VARCHAR(30) DEFAULT NULL AFTER company;
-- ALTER TABLE transfers ADD COLUMN source VARCHAR(30) DEFAULT 'manual' AFTER transaction_type;

-- ── ALTER: Viamericas / Intermex support ──
-- ALTER TABLE barri_reports ADD COLUMN report_type VARCHAR(30) DEFAULT 'barri' AFTER status;
-- ALTER TABLE barri_reports ADD COLUMN total_received_foreign DECIMAL(14,2) DEFAULT 0.00 AFTER total_agcomm;

-- ALTER TABLE barri_transactions ADD COLUMN amount_received DECIMAL(14,2) DEFAULT 0.00 AFTER total;
-- ALTER TABLE barri_transactions ADD COLUMN received_currency VARCHAR(10) DEFAULT NULL AFTER amount_received;
-- ALTER TABLE barri_transactions ADD COLUMN paying_bank VARCHAR(200) DEFAULT NULL AFTER received_currency;
-- ALTER TABLE barri_transactions ADD COLUMN destination_country VARCHAR(100) DEFAULT NULL AFTER paying_bank;
-- ALTER TABLE barri_transactions ADD COLUMN destination_state VARCHAR(100) DEFAULT NULL AFTER destination_country;
-- ALTER TABLE barri_transactions ADD COLUMN destination_city VARCHAR(200) DEFAULT NULL AFTER destination_state;
-- ALTER TABLE barri_transactions ADD COLUMN payment_date DATETIME DEFAULT NULL AFTER destination_city;
-- ALTER TABLE barri_transactions ADD COLUMN transaction_status VARCHAR(50) DEFAULT NULL AFTER payment_date;
-- ALTER TABLE barri_reports ADD COLUMN finance_class VARCHAR(30) NOT NULL DEFAULT 'standard';
-- ALTER TABLE barri_reports ADD COLUMN data_completeness VARCHAR(30) NOT NULL DEFAULT 'complete';
-- ALTER TABLE accounting_entries ADD COLUMN finance_class VARCHAR(30) NOT NULL DEFAULT 'standard';
-- ALTER TABLE accounting_entries ADD COLUMN data_completeness VARCHAR(30) NOT NULL DEFAULT 'complete';
-- ALTER TABLE accounting_entries ADD COLUMN source_report_id INT UNSIGNED DEFAULT NULL;


-- ── Receipts / business expenses ──
CREATE TABLE receipts (
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

CREATE TABLE receipt_items (
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
