-- Rename legacy suly_ledger → internal_ledger (MySQL)
-- Skip if already applied.

RENAME TABLE suly_ledger TO internal_ledger;

ALTER TABLE internal_ledger
  CHANGE COLUMN owed_to_suly owed_to_store DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  CHANGE COLUMN suly_owes store_owes DECIMAL(12,2) NOT NULL DEFAULT 0.00;

-- Optional index renames (ignore errors if names already updated)
-- ALTER TABLE internal_ledger RENAME INDEX idx_suly_ledger_entry_source TO idx_internal_ledger_entry_source;
-- ALTER TABLE internal_ledger RENAME INDEX idx_suly_ledger_source_ref TO idx_internal_ledger_source_ref;

SELECT 'internal_ledger rename complete' AS result;
