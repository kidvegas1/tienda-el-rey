ALTER TABLE barri_reports ADD COLUMN IF NOT EXISTS finance_class VARCHAR(30) NOT NULL DEFAULT 'standard';
ALTER TABLE barri_reports ADD COLUMN IF NOT EXISTS data_completeness VARCHAR(30) NOT NULL DEFAULT 'complete';

ALTER TABLE accounting_entries ADD COLUMN IF NOT EXISTS finance_class VARCHAR(30) NOT NULL DEFAULT 'standard';
ALTER TABLE accounting_entries ADD COLUMN IF NOT EXISTS data_completeness VARCHAR(30) NOT NULL DEFAULT 'complete';
ALTER TABLE accounting_entries ADD COLUMN IF NOT EXISTS source_report_id INTEGER DEFAULT NULL;

CREATE INDEX IF NOT EXISTS idx_accounting_source_report ON accounting_entries (source_report_id);
