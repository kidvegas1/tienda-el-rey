-- Reclassify nameless Viamericas Money Orders as Otros servicios (not FinCEN clients).
-- Applied on 2026-07-14 for client #473 "Money Order".

ALTER TABLE accounting_entries ADD COLUMN IF NOT EXISTS source_report_id INTEGER NULL;
CREATE INDEX IF NOT EXISTS idx_accounting_source_report ON accounting_entries (source_report_id);

-- Example cleanup (adjust client id if needed):
-- UPDATE barri_transactions
-- SET transfer_id = NULL, pushed_to_transfers = FALSE, matched = FALSE, client_id = NULL,
--     customer_name = CASE WHEN NULLIF(TRIM(reference_number), '') IS NOT NULL
--       THEN 'MO ' || TRIM(reference_number) ELSE 'Money Order' END
-- WHERE client_id = <money_order_client_id>;
-- DELETE FROM transfers WHERE client_id = <money_order_client_id>;
-- DELETE FROM clients WHERE id = <money_order_client_id>;
