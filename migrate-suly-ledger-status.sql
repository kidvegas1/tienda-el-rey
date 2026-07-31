-- Libro El Rey: open/paid status tracking (idempotent — safe to re-run on production)

ALTER TABLE internal_ledger ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'open';
ALTER TABLE internal_ledger ADD COLUMN IF NOT EXISTS paid_at TIMESTAMPTZ;
ALTER TABLE internal_ledger ADD COLUMN IF NOT EXISTS paid_by_user_id INTEGER;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.table_constraints
    WHERE constraint_schema = current_schema()
      AND table_name = 'internal_ledger'
      AND constraint_name = 'internal_ledger_paid_by_user_id_fkey'
  ) THEN
    ALTER TABLE internal_ledger
      ADD CONSTRAINT internal_ledger_paid_by_user_id_fkey
      FOREIGN KEY (paid_by_user_id) REFERENCES users(id);
  END IF;
END $$;

UPDATE internal_ledger SET status = 'open' WHERE status IS NULL;

CREATE INDEX IF NOT EXISTS idx_internal_ledger_status ON internal_ledger (status);

SELECT 'internal_ledger status migration complete' AS result;
