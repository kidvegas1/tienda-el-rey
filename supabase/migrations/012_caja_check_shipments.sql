-- Cross-store check shipments to clear company negative balances (Caja)

CREATE TABLE IF NOT EXISTS caja_check_shipments (
    id BIGSERIAL PRIMARY KEY,
    from_store_id BIGINT NOT NULL REFERENCES stores(id),
    to_store_id BIGINT NOT NULL REFERENCES stores(id),
    company VARCHAR(100) NOT NULL,
    session_id BIGINT REFERENCES caja_sessions(id) ON DELETE SET NULL,
    shipment_date DATE NOT NULL,
    check_amount NUMERIC(12,2) NOT NULL DEFAULT 0,
    cash_received NUMERIC(12,2) NOT NULL DEFAULT 0,
    check_number VARCHAR(80),
    image_path VARCHAR(500),
    image_paths_json TEXT,
    notes TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'sent',
    created_by_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_caja_ship_from_date ON caja_check_shipments (from_store_id, shipment_date);
CREATE INDEX IF NOT EXISTS idx_caja_ship_to_company ON caja_check_shipments (to_store_id, company);
CREATE INDEX IF NOT EXISTS idx_caja_ship_status ON caja_check_shipments (status);

ALTER TABLE caja_sessions
    ADD COLUMN IF NOT EXISTS cash_received NUMERIC(12,2) NOT NULL DEFAULT 0;
