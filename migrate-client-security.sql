-- Client activity log, record requests, and transfer security alerts
CREATE TABLE IF NOT EXISTS client_activity_log (
    id SERIAL PRIMARY KEY,
    client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    store_id INTEGER REFERENCES stores(id),
    actor_user_id INTEGER REFERENCES users(id),
    event_type VARCHAR(50) NOT NULL,
    summary VARCHAR(500) NOT NULL DEFAULT '',
    payload TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_client_activity_client ON client_activity_log (client_id);
CREATE INDEX IF NOT EXISTS idx_client_activity_created ON client_activity_log (created_at);
CREATE INDEX IF NOT EXISTS idx_client_activity_event ON client_activity_log (event_type);

CREATE TABLE IF NOT EXISTS client_record_requests (
    id SERIAL PRIMARY KEY,
    requester_name VARCHAR(200) NOT NULL,
    requester_phone VARCHAR(30),
    matched_client_id INTEGER REFERENCES clients(id),
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    date_from DATE,
    date_to DATE,
    fulfilled_by_user_id INTEGER REFERENCES users(id),
    fulfillment_notes TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    fulfilled_at TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS transfer_security_alerts (
    id SERIAL PRIMARY KEY,
    alert_type VARCHAR(50) NOT NULL,
    severity VARCHAR(20) NOT NULL DEFAULT 'medium',
    client_id INTEGER REFERENCES clients(id),
    store_id INTEGER REFERENCES stores(id),
    transfer_id INTEGER REFERENCES transfers(id),
    title VARCHAR(200) NOT NULL,
    details TEXT NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    detected_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    resolved_at TIMESTAMPTZ,
    resolved_by_user_id INTEGER REFERENCES users(id),
    resolution_notes TEXT
);

CREATE INDEX IF NOT EXISTS idx_security_alerts_status ON transfer_security_alerts (status);
CREATE INDEX IF NOT EXISTS idx_security_alerts_type ON transfer_security_alerts (alert_type);
CREATE INDEX IF NOT EXISTS idx_security_alerts_client ON transfer_security_alerts (client_id);
CREATE INDEX IF NOT EXISTS idx_security_alerts_detected ON transfer_security_alerts (detected_at);
