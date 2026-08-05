-- Supplier price comparison (PostgreSQL)
-- Admin uploads invoices → line unit costs → cheapest-supplier recommendations

CREATE TABLE IF NOT EXISTS suppliers (
  id SERIAL PRIMARY KEY,
  store_id INTEGER DEFAULT NULL REFERENCES stores(id) ON DELETE CASCADE,
  name VARCHAR(200) NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Unique supplier name per store (0 = shared/global)
CREATE UNIQUE INDEX IF NOT EXISTS suppliers_store_name_uq
  ON suppliers (COALESCE(store_id, 0), LOWER(name));

CREATE INDEX IF NOT EXISTS idx_suppliers_store ON suppliers (store_id);

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'suppliers_set_updated_at') THEN
    CREATE TRIGGER suppliers_set_updated_at
      BEFORE UPDATE ON suppliers
      FOR EACH ROW
      EXECUTE FUNCTION set_updated_at();
  END IF;
END $$;

CREATE TABLE IF NOT EXISTS supplier_prices (
  id SERIAL PRIMARY KEY,
  supplier_id INTEGER NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
  inventory_id INTEGER DEFAULT NULL REFERENCES inventory(id) ON DELETE SET NULL,
  barcode VARCHAR(100) DEFAULT NULL,
  product_name VARCHAR(300) NOT NULL DEFAULT '',
  unit_cost NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
  quantity NUMERIC(12, 3) NOT NULL DEFAULT 1.000,
  invoice_date DATE DEFAULT NULL,
  source_path VARCHAR(500) DEFAULT NULL,
  observed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  created_by INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_supplier_prices_inventory_cost
  ON supplier_prices (inventory_id, unit_cost);
CREATE INDEX IF NOT EXISTS idx_supplier_prices_barcode
  ON supplier_prices (barcode);
CREATE INDEX IF NOT EXISTS idx_supplier_prices_supplier
  ON supplier_prices (supplier_id);
