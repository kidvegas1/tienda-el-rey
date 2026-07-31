-- Receipts / business expenses (PostgreSQL)
-- Converted from migrate-receipts.sql (MySQL → PostgreSQL)

DO $$
BEGIN
  CREATE TYPE receipt_status AS ENUM ('pending', 'approved', 'rejected');
EXCEPTION
  WHEN duplicate_object THEN NULL;
END $$;

CREATE TABLE IF NOT EXISTS receipts (
  id SERIAL PRIMARY KEY,
  store_id INTEGER NOT NULL REFERENCES stores(id),
  user_id INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
  image_path VARCHAR(500) DEFAULT NULL,
  vendor VARCHAR(200) DEFAULT NULL,
  receipt_date DATE DEFAULT NULL,
  subtotal NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
  tax NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
  total NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
  category VARCHAR(100) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  status receipt_status NOT NULL DEFAULT 'pending',
  ai_raw_json JSONB DEFAULT NULL,
  accounting_entry_id INTEGER DEFAULT NULL REFERENCES accounting_entries(id) ON DELETE SET NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_receipts_store_date ON receipts (store_id, receipt_date);
CREATE INDEX IF NOT EXISTS idx_receipts_store_status ON receipts (store_id, status);

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger WHERE tgname = 'receipts_set_updated_at'
  ) THEN
    CREATE TRIGGER receipts_set_updated_at
      BEFORE UPDATE ON receipts
      FOR EACH ROW
      EXECUTE FUNCTION set_updated_at();
  END IF;
END $$;

CREATE TABLE IF NOT EXISTS receipt_items (
  id SERIAL PRIMARY KEY,
  receipt_id INTEGER NOT NULL REFERENCES receipts(id) ON DELETE CASCADE,
  description VARCHAR(500) NOT NULL,
  quantity NUMERIC(10, 3) NOT NULL DEFAULT 1.000,
  amount NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
  editable BOOLEAN NOT NULL DEFAULT TRUE,
  sort_order SMALLINT NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_receipt_items_receipt ON receipt_items (receipt_id);
