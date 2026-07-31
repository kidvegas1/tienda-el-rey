-- Tienda Hispana El Rey Dashboard — Initial PostgreSQL/Supabase schema
-- Converted from schema.sql + migrate-*.sql (MySQL 8.x → PostgreSQL)

-- ── ENUM types ──

CREATE TYPE user_role AS ENUM ('admin', 'manager', 'cashier', 'employee');
CREATE TYPE caja_session_status AS ENUM ('open', 'closed');
CREATE TYPE employee_status AS ENUM ('active', 'inactive');
CREATE TYPE clock_in_status AS ENUM ('clocked_in', 'clocked_out', 'missed');
CREATE TYPE accounting_entry_type AS ENUM ('receivable', 'payable');
CREATE TYPE event_status AS ENUM ('booked', 'confirmed', 'completed', 'cancelled');
CREATE TYPE plate_status AS ENUM ('pending', 'in_progress', 'completed', 'delivered');
CREATE TYPE excel_import_status AS ENUM ('pending', 'processing', 'completed', 'failed');
CREATE TYPE barri_report_status AS ENUM ('pending', 'processed', 'error');

-- ── updated_at trigger helper ──

CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = NOW();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- ── Stores ──

CREATE TABLE stores (
  id SERIAL PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  address VARCHAR(255) DEFAULT NULL,
  phone VARCHAR(20) DEFAULT NULL,
  barri_agency_number VARCHAR(20) DEFAULT NULL,
  barri_operator_number VARCHAR(20) DEFAULT NULL,
  viamericas_agency_number VARCHAR(20) DEFAULT NULL,
  intercambio_agency_number VARCHAR(20) DEFAULT NULL,
  intermex_agency_number VARCHAR(20) DEFAULT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ── Users ──

CREATE TABLE users (
  id SERIAL PRIMARY KEY,
  store_id INTEGER DEFAULT NULL REFERENCES stores(id) ON DELETE SET NULL,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role user_role NOT NULL DEFAULT 'employee',
  photo_url VARCHAR(500) DEFAULT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ── Clients ──

CREATE TABLE clients (
  id SERIAL PRIMARY KEY,
  client_code VARCHAR(30) DEFAULT NULL,
  name VARCHAR(200) NOT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  monthly_limit NUMERIC(10, 2) NOT NULL DEFAULT 3000.00,
  income_verified BOOLEAN NOT NULL DEFAULT FALSE,
  income_doc_path VARCHAR(500) DEFAULT NULL,
  sender_id_path VARCHAR(500) DEFAULT NULL,
  sender_id_type VARCHAR(50) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_clients_name ON clients (name);
CREATE INDEX idx_clients_code ON clients (client_code);

CREATE TRIGGER clients_set_updated_at
  BEFORE UPDATE ON clients
  FOR EACH ROW
  EXECUTE FUNCTION set_updated_at();

-- ── Receivers ──

CREATE TABLE receivers (
  id SERIAL PRIMARY KEY,
  client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
  name VARCHAR(200) NOT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  id_path VARCHAR(500) DEFAULT NULL,
  id_type VARCHAR(50) DEFAULT NULL,
  destination_country VARCHAR(100) DEFAULT NULL,
  destination_city VARCHAR(200) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_receivers_client_name ON receivers (client_id, name);

-- ── Transfers ──

CREATE TABLE transfers (
  id SERIAL PRIMARY KEY,
  client_id INTEGER NOT NULL REFERENCES clients(id),
  receiver_id INTEGER DEFAULT NULL REFERENCES receivers(id),
  store_id INTEGER NOT NULL REFERENCES stores(id),
  transaction_code VARCHAR(30) DEFAULT NULL,
  beneficiary VARCHAR(200) NOT NULL,
  date_sent TIMESTAMPTZ NOT NULL,
  date_paid TIMESTAMPTZ DEFAULT NULL,
  amount_usd NUMERIC(12, 2) NOT NULL,
  fee NUMERIC(10, 2) DEFAULT NULL,
  tax NUMERIC(10, 2) DEFAULT NULL,
  amount_local NUMERIC(14, 2) DEFAULT NULL,
  currency VARCHAR(10) DEFAULT 'MXN',
  paying_bank VARCHAR(100) DEFAULT NULL,
  destination_country VARCHAR(100) DEFAULT NULL,
  destination_city VARCHAR(200) DEFAULT NULL,
  company VARCHAR(50) DEFAULT NULL,
  transaction_type VARCHAR(30) DEFAULT NULL,
  source VARCHAR(30) DEFAULT 'manual',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_transfers_client_date ON transfers (client_id, date_sent);
CREATE INDEX idx_transfers_store_date ON transfers (store_id, date_sent);

-- ── Caja Sessions ──

CREATE TABLE caja_sessions (
  id SERIAL PRIMARY KEY,
  store_id INTEGER NOT NULL REFERENCES stores(id),
  user_id INTEGER NOT NULL REFERENCES users(id),
  session_date DATE NOT NULL,
  cashier_name VARCHAR(100) DEFAULT NULL,
  opening_balance NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
  closing_balance NUMERIC(12, 2) DEFAULT NULL,
  status caja_session_status NOT NULL DEFAULT 'open',
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_caja_sessions_store_date ON caja_sessions (store_id, session_date);

-- ── Caja Entries ──

CREATE TABLE caja_entries (
  id SERIAL PRIMARY KEY,
  session_id INTEGER NOT NULL REFERENCES caja_sessions(id) ON DELETE CASCADE,
  company VARCHAR(100) NOT NULL,
  cash_in NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
  checks_debits NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
  total NUMERIC(12, 2) GENERATED ALWAYS AS (cash_in + checks_debits) STORED,
  notes VARCHAR(500) DEFAULT NULL,
  sort_order SMALLINT DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_caja_entries_session ON caja_entries (session_id);

-- ── Caja Denominations ──

CREATE TABLE caja_denominations (
  id SERIAL PRIMARY KEY,
  session_id INTEGER NOT NULL REFERENCES caja_sessions(id) ON DELETE CASCADE,
  denomination NUMERIC(6, 2) NOT NULL,
  count INTEGER NOT NULL DEFAULT 0,
  subtotal NUMERIC(12, 2) GENERATED ALWAYS AS (denomination * count) STORED
);

-- ── Company Flags (manual admin risk markers) ──

CREATE TABLE company_flags (
  id SERIAL PRIMARY KEY,
  company_key VARCHAR(120) NOT NULL,
  company_label VARCHAR(120) NOT NULL,
  reason TEXT NOT NULL DEFAULT '',
  flagged_by_user_id INTEGER REFERENCES users(id),
  flagged_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  cleared_at TIMESTAMPTZ,
  cleared_by_user_id INTEGER REFERENCES users(id),
  is_active BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE INDEX idx_company_flags_key ON company_flags (company_key);
CREATE INDEX idx_company_flags_active ON company_flags (is_active);
CREATE UNIQUE INDEX idx_company_flags_active_key ON company_flags (company_key) WHERE is_active IS TRUE;

-- ── Client activity, record requests, transfer security ──

CREATE TABLE client_activity_log (
  id SERIAL PRIMARY KEY,
  client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
  store_id INTEGER REFERENCES stores(id),
  actor_user_id INTEGER REFERENCES users(id),
  event_type VARCHAR(50) NOT NULL,
  summary VARCHAR(500) NOT NULL DEFAULT '',
  payload TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_client_activity_client ON client_activity_log (client_id);
CREATE INDEX idx_client_activity_created ON client_activity_log (created_at);
CREATE INDEX idx_client_activity_event ON client_activity_log (event_type);

CREATE TABLE client_record_requests (
  id SERIAL PRIMARY KEY,
  requester_name VARCHAR(200) NOT NULL,
  requester_phone VARCHAR(30),
  matched_client_id INTEGER REFERENCES clients(id),
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  date_from DATE,
  date_to DATE,
  fulfilled_by_user_id INTEGER REFERENCES users(id),
  fulfillment_notes TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  fulfilled_at TIMESTAMPTZ
);

CREATE TABLE transfer_security_alerts (
  id SERIAL PRIMARY KEY,
  alert_type VARCHAR(50) NOT NULL,
  severity VARCHAR(20) NOT NULL DEFAULT 'medium',
  client_id INTEGER REFERENCES clients(id),
  store_id INTEGER REFERENCES stores(id),
  transfer_id INTEGER REFERENCES transfers(id),
  title VARCHAR(200) NOT NULL,
  details TEXT NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  detected_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  resolved_at TIMESTAMPTZ,
  resolved_by_user_id INTEGER REFERENCES users(id),
  resolution_notes TEXT
);

CREATE INDEX idx_transfer_security_status ON transfer_security_alerts (status);
CREATE INDEX idx_transfer_security_type ON transfer_security_alerts (alert_type);
CREATE INDEX idx_transfer_security_client ON transfer_security_alerts (client_id);
CREATE INDEX idx_transfer_security_detected ON transfer_security_alerts (detected_at);

-- ── Libro El Rey ──

CREATE TABLE suly_ledger (
  id SERIAL PRIMARY KEY,
  store_id INTEGER NOT NULL REFERENCES stores(id),
  employee_name VARCHAR(100) DEFAULT NULL,
  description VARCHAR(500) NOT NULL,
  owed_to_suly NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
  suly_owes NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
  entry_date DATE NOT NULL,
  notes TEXT DEFAULT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  paid_at TIMESTAMPTZ DEFAULT NULL,
  paid_by_user_id INTEGER DEFAULT NULL REFERENCES users(id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_suly_ledger_store_date ON suly_ledger (store_id, entry_date);
CREATE INDEX idx_suly_ledger_status ON suly_ledger (status);

-- ── Employees ──

CREATE TABLE employees (
  id SERIAL PRIMARY KEY,
  store_id INTEGER NOT NULL REFERENCES stores(id),
  user_id INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
  name VARCHAR(100) NOT NULL,
  hourly_rate NUMERIC(8, 2) DEFAULT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  status employee_status NOT NULL DEFAULT 'active',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ── Clock Ins ──

CREATE TABLE clock_ins (
  id SERIAL PRIMARY KEY,
  employee_id INTEGER NOT NULL REFERENCES employees(id),
  store_id INTEGER NOT NULL REFERENCES stores(id),
  clock_in_time TIMESTAMPTZ NOT NULL,
  clock_out_time TIMESTAMPTZ DEFAULT NULL,
  photo_path VARCHAR(500) NOT NULL,
  clock_out_photo_path VARCHAR(500) DEFAULT NULL,
  hours_worked NUMERIC(5, 2) DEFAULT NULL,
  status clock_in_status NOT NULL DEFAULT 'clocked_in',
  notes VARCHAR(500) DEFAULT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_clock_ins_employee_date ON clock_ins (employee_id, clock_in_time);
CREATE INDEX idx_clock_ins_store_date ON clock_ins (store_id, clock_in_time);

-- ── Schedules ──

CREATE TABLE schedules (
  id SERIAL PRIMARY KEY,
  store_id INTEGER NOT NULL REFERENCES stores(id),
  employee_id INTEGER NOT NULL REFERENCES employees(id),
  week_start DATE NOT NULL,
  day_of_week SMALLINT NOT NULL, -- 0=Mon, 6=Sun
  start_time TIME NOT NULL,
  end_time TIME NOT NULL
);

CREATE INDEX idx_schedules_store_week ON schedules (store_id, week_start);

-- ── Transfer Statistics ──

CREATE TABLE transfer_statistics (
  id SERIAL PRIMARY KEY,
  store_id INTEGER NOT NULL REFERENCES stores(id),
  company VARCHAR(50) NOT NULL,
  month SMALLINT NOT NULL,
  year SMALLINT NOT NULL,
  transfer_count INTEGER NOT NULL DEFAULT 0,
  total_usd NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
  CONSTRAINT uk_transfer_statistics_store_company_period UNIQUE (store_id, company, month, year)
);

-- ── Accounting Entries ──

CREATE TABLE accounting_entries (
  id SERIAL PRIMARY KEY,
  store_id INTEGER NOT NULL REFERENCES stores(id),
  category VARCHAR(100) NOT NULL,
  description VARCHAR(500) NOT NULL,
  amount NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
  entry_type accounting_entry_type NOT NULL,
  entry_date DATE NOT NULL,
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_accounting_entries_store_cat ON accounting_entries (store_id, category);

-- ── Inventory (Medicine) ──

CREATE TABLE inventory (
  id SERIAL PRIMARY KEY,
  store_id INTEGER NOT NULL REFERENCES stores(id),
  product_name VARCHAR(200) NOT NULL,
  quantity INTEGER NOT NULL DEFAULT 0,
  description VARCHAR(500) DEFAULT NULL,
  cost_price NUMERIC(10, 2) DEFAULT NULL,
  retail_price NUMERIC(10, 2) DEFAULT NULL,
  low_stock_threshold INTEGER NOT NULL DEFAULT 5,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_inventory_store_product ON inventory (store_id, product_name);

CREATE TRIGGER inventory_set_updated_at
  BEFORE UPDATE ON inventory
  FOR EACH ROW
  EXECUTE FUNCTION set_updated_at();

-- ── Events (Salon de Eventos) ──

CREATE TABLE events (
  id SERIAL PRIMARY KEY,
  store_id INTEGER NOT NULL REFERENCES stores(id),
  client_name VARCHAR(200) NOT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  event_date DATE NOT NULL,
  deposit NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
  balance NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
  total NUMERIC(10, 2) GENERATED ALWAYS AS (deposit + balance) STORED,
  color_theme VARCHAR(100) DEFAULT NULL,
  package VARCHAR(100) DEFAULT NULL,
  payment_method VARCHAR(100) DEFAULT NULL,
  status event_status NOT NULL DEFAULT 'booked',
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_events_store_date ON events (store_id, event_date);

-- ── Plates (Vehicle Registration) ──

CREATE TABLE plates (
  id SERIAL PRIMARY KEY,
  store_id INTEGER NOT NULL REFERENCES stores(id),
  client_name VARCHAR(200) NOT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  vin VARCHAR(50) DEFAULT NULL,
  service_type VARCHAR(100) NOT NULL,
  delivery_date DATE DEFAULT NULL,
  payment NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
  balance NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
  total NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
  agent_name VARCHAR(100) DEFAULT NULL,
  agent_fee NUMERIC(10, 2) DEFAULT NULL,
  status plate_status NOT NULL DEFAULT 'pending',
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ── Secure Notes ──

CREATE TABLE secure_notes (
  id SERIAL PRIMARY KEY,
  store_id INTEGER NOT NULL REFERENCES stores(id),
  title VARCHAR(200) NOT NULL,
  content TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TRIGGER secure_notes_set_updated_at
  BEFORE UPDATE ON secure_notes
  FOR EACH ROW
  EXECUTE FUNCTION set_updated_at();

-- ── Excel Imports ──

CREATE TABLE excel_imports (
  id SERIAL PRIMARY KEY,
  store_id INTEGER NOT NULL REFERENCES stores(id),
  user_id INTEGER NOT NULL REFERENCES users(id),
  filename VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  sheet_mapping JSONB DEFAULT NULL,
  rows_imported INTEGER DEFAULT 0,
  status excel_import_status NOT NULL DEFAULT 'pending',
  errors TEXT DEFAULT NULL,
  imported_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ── Barri Reports ──

CREATE TABLE barri_reports (
  id SERIAL PRIMARY KEY,
  store_id INTEGER NOT NULL REFERENCES stores(id),
  user_id INTEGER NOT NULL REFERENCES users(id),
  agency_number VARCHAR(20) DEFAULT NULL,
  agency_name VARCHAR(200) DEFAULT NULL,
  agency_address VARCHAR(300) DEFAULT NULL,
  company VARCHAR(50) DEFAULT 'Barri',
  store_name VARCHAR(200) DEFAULT NULL,
  ar_executive VARCHAR(200) DEFAULT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  report_date_from DATE NOT NULL,
  report_date_to DATE NOT NULL,
  beginning_balance NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
  ending_balance NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
  total_transactions INTEGER NOT NULL DEFAULT 0,
  total_principal NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
  total_fees NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
  total_tax NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
  total_amount NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
  total_agcomm NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
  total_received_foreign NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
  filename VARCHAR(255) NOT NULL DEFAULT '',
  original_name VARCHAR(255) NOT NULL DEFAULT '',
  status barri_report_status NOT NULL DEFAULT 'pending',
  report_type VARCHAR(30) NOT NULL DEFAULT 'barri',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_barri_reports_store_date ON barri_reports (store_id, report_date_from);

-- ── Barri Transactions ──

CREATE TABLE barri_transactions (
  id SERIAL PRIMARY KEY,
  report_id INTEGER NOT NULL REFERENCES barri_reports(id) ON DELETE CASCADE,
  store_id INTEGER NOT NULL REFERENCES stores(id),
  client_id INTEGER DEFAULT NULL REFERENCES clients(id) ON DELETE SET NULL,
  transaction_time TIME NOT NULL,
  transaction_date DATE NOT NULL,
  transaction_type VARCHAR(30) NOT NULL DEFAULT 'giros',
  reference_number VARCHAR(30) NOT NULL,
  customer_name VARCHAR(200) NOT NULL,
  beneficiary_name VARCHAR(200) DEFAULT NULL,
  description VARCHAR(300) DEFAULT NULL,
  operator VARCHAR(30) DEFAULT NULL,
  quantity INTEGER NOT NULL DEFAULT 1,
  principal NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
  fee NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
  tax NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
  total NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
  amount_received NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
  received_currency VARCHAR(10) DEFAULT NULL,
  paying_bank VARCHAR(200) DEFAULT NULL,
  destination_country VARCHAR(100) DEFAULT NULL,
  destination_state VARCHAR(100) DEFAULT NULL,
  destination_city VARCHAR(200) DEFAULT NULL,
  payment_date TIMESTAMPTZ DEFAULT NULL,
  transaction_status VARCHAR(50) DEFAULT NULL,
  running_balance NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
  ag_commission NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
  variable_fee NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
  variable_fx NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
  matched BOOLEAN NOT NULL DEFAULT FALSE,
  pushed_to_transfers BOOLEAN NOT NULL DEFAULT FALSE,
  transfer_id INTEGER DEFAULT NULL REFERENCES transfers(id) ON DELETE SET NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_barri_transactions_report ON barri_transactions (report_id);
CREATE INDEX idx_barri_transactions_client ON barri_transactions (client_id);
CREATE INDEX idx_barri_transactions_reference ON barri_transactions (reference_number);

-- ── App Settings ──

CREATE TABLE app_settings (
  setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
  setting_value VARCHAR(500) NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TRIGGER app_settings_set_updated_at
  BEFORE UPDATE ON app_settings
  FOR EACH ROW
  EXECUTE FUNCTION set_updated_at();

-- ── Seed data ──
-- Stores are created by admins in the app (Tiendas). Do not seed demo locations.

-- Administrator credentials are intentionally not seeded.
-- Run scripts/bootstrap-admin.php with deployment-specific environment values.

INSERT INTO app_settings (setting_key, setting_value) VALUES
  ('fincen_global_limit', '3000'),
  ('fincen_period', 'month')
ON CONFLICT (setting_key) DO NOTHING;
