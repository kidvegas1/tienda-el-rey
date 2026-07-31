-- Sales log: refund / erase tracking on inventory_movements
-- mysql -u root tienda_el_rey < migrate-sales-log.sql
-- Safe to re-run only if columns are missing (will error if already applied).

ALTER TABLE inventory_movements
  ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER notes,
  ADD COLUMN refunded_at TIMESTAMP NULL DEFAULT NULL AFTER status,
  ADD COLUMN refunded_by_user_id INT UNSIGNED NULL DEFAULT NULL AFTER refunded_at,
  ADD COLUMN voided_at TIMESTAMP NULL DEFAULT NULL AFTER refunded_by_user_id,
  ADD COLUMN voided_by_user_id INT UNSIGNED NULL DEFAULT NULL AFTER voided_at;

ALTER TABLE inventory_movements
  ADD INDEX idx_inventory_movements_store_type_status (store_id, movement_type, status, created_at);

SELECT 'sales log migration complete' AS result;
