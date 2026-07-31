-- Monthly store goals (envíos / cambio de cheques)

CREATE TABLE IF NOT EXISTS store_goals (
    id BIGSERIAL PRIMARY KEY,
    store_id BIGINT NOT NULL REFERENCES stores(id) ON DELETE CASCADE,
    metric_type VARCHAR(30) NOT NULL CHECK (metric_type IN ('envios_count','envios_volume','cambio_count','cambio_volume')),
    period_year SMALLINT NOT NULL,
    period_month SMALLINT NOT NULL CHECK (period_month BETWEEN 1 AND 12),
    target_value NUMERIC(14,2) NOT NULL DEFAULT 0,
    notes TEXT,
    created_by_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ,
    UNIQUE (store_id, metric_type, period_year, period_month)
);

CREATE INDEX IF NOT EXISTS idx_store_goals_store_period ON store_goals (store_id, period_year, period_month);
