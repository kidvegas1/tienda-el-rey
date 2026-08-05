-- Manual remittance FX rates for public home-page marketing display.
CREATE TABLE IF NOT EXISTS remittance_exchange_rates (
    id SERIAL PRIMARY KEY,
    country_code VARCHAR(2) NOT NULL,
    country_name VARCHAR(100) NOT NULL,
    currency_code VARCHAR(10) NOT NULL,
    rate_per_usd NUMERIC(18, 4) NOT NULL DEFAULT 0,
    published BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    note VARCHAR(200) DEFAULT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by_user_id INTEGER REFERENCES users(id),
    CONSTRAINT remittance_exchange_rates_country_code_key UNIQUE (country_code)
);

CREATE INDEX IF NOT EXISTS idx_remittance_fx_published
    ON remittance_exchange_rates (published, sort_order, country_name);

INSERT INTO remittance_exchange_rates (country_code, country_name, currency_code, rate_per_usd, published, sort_order)
VALUES
    ('MX', 'México', 'MXN', 0, FALSE, 10),
    ('GT', 'Guatemala', 'GTQ', 0, FALSE, 20),
    ('HN', 'Honduras', 'HNL', 0, FALSE, 30),
    ('SV', 'El Salvador', 'USD', 1, FALSE, 40),
    ('NI', 'Nicaragua', 'NIO', 0, FALSE, 50),
    ('CR', 'Costa Rica', 'CRC', 0, FALSE, 60),
    ('CO', 'Colombia', 'COP', 0, FALSE, 70),
    ('DO', 'República Dominicana', 'DOP', 0, FALSE, 80),
    ('PE', 'Perú', 'PEN', 0, FALSE, 90),
    ('EC', 'Ecuador', 'USD', 1, FALSE, 100),
    ('BO', 'Bolivia', 'BOB', 0, FALSE, 110),
    ('PY', 'Paraguay', 'PYG', 0, FALSE, 120),
    ('BR', 'Brasil', 'BRL', 0, FALSE, 130)
ON CONFLICT (country_code) DO NOTHING;
