USE tienda_el_rey;

ALTER TABLE stores
    ADD COLUMN publish_inventory TINYINT(1) NOT NULL DEFAULT 1 AFTER active,
    ADD COLUMN publish_prices TINYINT(1) NOT NULL DEFAULT 0 AFTER publish_inventory;

UPDATE stores SET publish_inventory = 1 WHERE publish_inventory IS NULL;
UPDATE stores SET publish_prices = 0 WHERE publish_prices IS NULL;

SELECT 'catalog publish migration complete' AS result;
