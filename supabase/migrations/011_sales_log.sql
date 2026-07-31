-- Sales log: refund / erase status on inventory_movements

ALTER TABLE inventory_movements
  ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'active',
  ADD COLUMN IF NOT EXISTS refunded_at TIMESTAMPTZ,
  ADD COLUMN IF NOT EXISTS refunded_by_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS voided_at TIMESTAMPTZ,
  ADD COLUMN IF NOT EXISTS voided_by_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
    WHERE conname = 'inventory_movements_status_check'
  ) THEN
    ALTER TABLE inventory_movements
      ADD CONSTRAINT inventory_movements_status_check
      CHECK (status IN ('active', 'refunded', 'voided'));
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_inventory_movements_store_type_status
  ON inventory_movements (store_id, movement_type, status, created_at);
