-- Manual company risk flags (additive; does not modify caja history)
CREATE TABLE IF NOT EXISTS company_flags (
    id SERIAL PRIMARY KEY,
    company_key VARCHAR(120) NOT NULL,
    company_label VARCHAR(120) NOT NULL,
    reason TEXT NOT NULL DEFAULT '',
    flagged_by_user_id INTEGER REFERENCES users(id),
    flagged_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    cleared_at TIMESTAMPTZ,
    cleared_by_user_id INTEGER REFERENCES users(id),
    is_active BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE INDEX IF NOT EXISTS idx_company_flags_key ON company_flags (company_key);
CREATE INDEX IF NOT EXISTS idx_company_flags_active ON company_flags (is_active);

CREATE UNIQUE INDEX IF NOT EXISTS idx_company_flags_active_key
    ON company_flags (company_key)
    WHERE is_active IS TRUE;
