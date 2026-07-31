-- Retail inventory fields, barcode scanning, and stock/sales movement ledger.

ALTER TABLE inventory
  ADD COLUMN IF NOT EXISTS barcode VARCHAR(64),
  ADD COLUMN IF NOT EXISTS sku VARCHAR(64),
  ADD COLUMN IF NOT EXISTS category VARCHAR(100),
  ADD COLUMN IF NOT EXISTS image_path VARCHAR(500),
  ADD COLUMN IF NOT EXISTS image_paths_json TEXT,
  ADD COLUMN IF NOT EXISTS taxable BOOLEAN NOT NULL DEFAULT TRUE,
  ADD COLUMN IF NOT EXISTS active BOOLEAN NOT NULL DEFAULT TRUE;

ALTER TABLE inventory
  ALTER COLUMN description TYPE TEXT;

CREATE UNIQUE INDEX IF NOT EXISTS uk_inventory_store_barcode
  ON inventory (store_id, barcode);

CREATE TABLE IF NOT EXISTS inventory_movements (
  id SERIAL PRIMARY KEY,
  store_id INTEGER NOT NULL REFERENCES stores(id),
  inventory_id INTEGER NOT NULL REFERENCES inventory(id),
  movement_type VARCHAR(20) NOT NULL
    CHECK (movement_type IN ('stock_in', 'sale', 'adjust')),
  quantity INTEGER NOT NULL,
  unit_price NUMERIC(10, 2),
  tax_rate NUMERIC(6, 3),
  tax_amount NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
  total_amount NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
  barcode VARCHAR(64),
  notes TEXT,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_inventory_movements_inventory_created
  ON inventory_movements (inventory_id, created_at);
CREATE INDEX IF NOT EXISTS idx_inventory_movements_store_created
  ON inventory_movements (store_id, created_at);
CREATE INDEX IF NOT EXISTS idx_inventory_movements_barcode
  ON inventory_movements (store_id, barcode);

INSERT INTO app_settings (setting_key, setting_value) VALUES
  ('global_tax_rate', '8.25'),
  ('tax_label', 'Texas Sales Tax')
ON CONFLICT (setting_key) DO NOTHING;
