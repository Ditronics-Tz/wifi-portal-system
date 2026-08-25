-- Migration 005: voucher sessions + security events (anti-sharing app layer)
-- Session is the primary authorization object. MAC/IP/acct id are supporting hints.

BEGIN;

CREATE TABLE IF NOT EXISTS voucher_sessions (
    id SERIAL PRIMARY KEY,
    voucher_id INT NOT NULL REFERENCES vouchers(id),
    client_mac VARCHAR(17) NULL,
    client_ip VARCHAR(45) NULL,
    gateway_session_id VARCHAR(64) NULL,
    nas_ip VARCHAR(45) NULL,
    started_at TIMESTAMP NOT NULL DEFAULT NOW(),
    last_seen_at TIMESTAMP NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMP NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active'
        CHECK (status IN ('active', 'closed', 'blocked')),
    closed_at TIMESTAMP NULL,
    close_reason VARCHAR(64) NULL
);

CREATE INDEX IF NOT EXISTS idx_voucher_sessions_voucher_status
    ON voucher_sessions (voucher_id, status);
CREATE INDEX IF NOT EXISTS idx_voucher_sessions_mac
    ON voucher_sessions (client_mac);
CREATE INDEX IF NOT EXISTS idx_voucher_sessions_acct
    ON voucher_sessions (gateway_session_id);

CREATE TABLE IF NOT EXISTS security_events (
    id SERIAL PRIMARY KEY,
    session_id INT NULL REFERENCES voucher_sessions(id),
    voucher_code VARCHAR(32) NULL,
    event_type VARCHAR(32) NOT NULL,
    severity VARCHAR(16) NOT NULL DEFAULT 'info'
        CHECK (severity IN ('info', 'low', 'medium', 'high')),
    metadata JSONB NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    resolved_at TIMESTAMP NULL
);

CREATE INDEX IF NOT EXISTS idx_security_events_created
    ON security_events (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_security_events_type
    ON security_events (event_type);
CREATE INDEX IF NOT EXISTS idx_security_events_voucher
    ON security_events (voucher_code);

COMMIT;
