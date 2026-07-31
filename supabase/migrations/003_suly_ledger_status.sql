-- Libro El Rey: open/paid status tracking (idempotent)

ALTER TABLE suly_ledger ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'open';
ALTER TABLE suly_ledger ADD COLUMN IF NOT EXISTS paid_at TIMESTAMPTZ;
ALTER TABLE suly_ledger ADD COLUMN IF NOT EXISTS paid_by_user_id INTEGER;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.table_constraints
    WHERE constraint_schema = current_schema()
      AND table_name = 'suly_ledger'
      AND constraint_name = 'suly_ledger_paid_by_user_id_fkey'
  ) THEN
    ALTER TABLE suly_ledger
      ADD CONSTRAINT suly_ledger_paid_by_user_id_fkey
      FOREIGN KEY (paid_by_user_id) REFERENCES users(id);
  END IF;
END $$;

UPDATE suly_ledger SET status = 'open' WHERE status IS NULL;

CREATE INDEX IF NOT EXISTS idx_suly_ledger_status ON suly_ledger (status);
