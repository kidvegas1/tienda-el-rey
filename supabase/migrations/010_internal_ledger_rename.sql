-- Rename legacy suly_ledger → internal_ledger (matches api/libro-interno.php)
-- Converted from migrate-rename-internal-ledger.sql (MySQL → PostgreSQL)
-- Idempotent: safe if 001 was already updated or rename was applied manually.

DO $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = current_schema() AND table_name = 'suly_ledger'
  ) AND NOT EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = current_schema() AND table_name = 'internal_ledger'
  ) THEN
    ALTER TABLE suly_ledger RENAME TO internal_ledger;
  END IF;
END $$;

DO $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'internal_ledger'
      AND column_name = 'owed_to_suly'
  ) THEN
    ALTER TABLE internal_ledger RENAME COLUMN owed_to_suly TO owed_to_store;
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'internal_ledger'
      AND column_name = 'suly_owes'
  ) THEN
    ALTER TABLE internal_ledger RENAME COLUMN suly_owes TO store_owes;
  END IF;
END $$;

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'idx_suly_ledger_store_date') THEN
    ALTER INDEX idx_suly_ledger_store_date RENAME TO idx_internal_ledger_store_date;
  END IF;

  IF EXISTS (SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'idx_suly_ledger_status') THEN
    ALTER INDEX idx_suly_ledger_status RENAME TO idx_internal_ledger_status;
  END IF;

  IF EXISTS (SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'idx_suly_ledger_entry_source') THEN
    ALTER INDEX idx_suly_ledger_entry_source RENAME TO idx_internal_ledger_entry_source;
  END IF;

  IF EXISTS (SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'idx_suly_ledger_source_ref') THEN
    ALTER INDEX idx_suly_ledger_source_ref RENAME TO idx_internal_ledger_source_ref;
  END IF;
END $$;

DO $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.table_constraints
    WHERE constraint_schema = current_schema()
      AND table_name = 'internal_ledger'
      AND constraint_name = 'suly_ledger_paid_by_user_id_fkey'
  ) THEN
    ALTER TABLE internal_ledger
      RENAME CONSTRAINT suly_ledger_paid_by_user_id_fkey TO internal_ledger_paid_by_user_id_fkey;
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.table_constraints
    WHERE constraint_schema = current_schema()
      AND table_name = 'internal_ledger'
      AND constraint_name = 'suly_ledger_barri_transaction_id_fkey'
  ) THEN
    ALTER TABLE internal_ledger
      RENAME CONSTRAINT suly_ledger_barri_transaction_id_fkey TO internal_ledger_barri_transaction_id_fkey;
  END IF;
END $$;
