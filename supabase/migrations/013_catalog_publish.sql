-- Per-store public catalog publish toggles.

ALTER TABLE stores
  ADD COLUMN IF NOT EXISTS publish_inventory BOOLEAN NOT NULL DEFAULT TRUE,
  ADD COLUMN IF NOT EXISTS publish_prices BOOLEAN NOT NULL DEFAULT FALSE;

UPDATE stores SET publish_inventory = TRUE WHERE publish_inventory IS NULL;
UPDATE stores SET publish_prices = FALSE WHERE publish_prices IS NULL;
