-- Reconciliation: cents tracking, ledger provenance, excel vs report variances

ALTER TABLE suly_ledger ADD COLUMN IF NOT EXISTS company VARCHAR(50) DEFAULT NULL;
ALTER TABLE suly_ledger ADD COLUMN IF NOT EXISTS entry_source VARCHAR(20) NOT NULL DEFAULT 'manual';
ALTER TABLE suly_ledger ADD COLUMN IF NOT EXISTS source_ref VARCHAR(100) DEFAULT NULL;
ALTER TABLE suly_ledger ADD COLUMN IF NOT EXISTS barri_transaction_id INTEGER DEFAULT NULL;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.table_constraints
    WHERE constraint_schema = current_schema()
      AND table_name = 'suly_ledger'
      AND constraint_name = 'suly_ledger_barri_transaction_id_fkey'
  ) THEN
    ALTER TABLE suly_ledger
      ADD CONSTRAINT suly_ledger_barri_transaction_id_fkey
      FOREIGN KEY (barri_transaction_id) REFERENCES barri_transactions(id) ON DELETE SET NULL;
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_suly_ledger_entry_source ON suly_ledger (entry_source);
CREATE INDEX IF NOT EXISTS idx_suly_ledger_source_ref ON suly_ledger (source_ref);

CREATE TABLE IF NOT EXISTS reconciliation_variances (
  id SERIAL PRIMARY KEY,
  store_id INTEGER NOT NULL REFERENCES stores(id),
  company VARCHAR(50) NOT NULL,
  variance_date DATE NOT NULL,
  metric VARCHAR(30) NOT NULL DEFAULT 'daily_total',
  excel_amount NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
  report_amount NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
  diff_amount NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
  excel_import_id INTEGER DEFAULT NULL REFERENCES excel_imports(id) ON DELETE SET NULL,
  barri_report_id INTEGER DEFAULT NULL REFERENCES barri_reports(id) ON DELETE SET NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  reviewed_at TIMESTAMPTZ DEFAULT NULL,
  reviewed_by_user_id INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_recon_variances_store_date ON reconciliation_variances (store_id, variance_date);
CREATE INDEX IF NOT EXISTS idx_recon_variances_status ON reconciliation_variances (status);

CREATE UNIQUE INDEX IF NOT EXISTS idx_recon_variances_unique
  ON reconciliation_variances (store_id, company, variance_date, metric);
