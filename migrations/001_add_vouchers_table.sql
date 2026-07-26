-- Migration: Add vouchers table to FreeRADIUS PostgreSQL database
-- Run this against the 'radius' database

-- Voucher table - source of truth for voucher state and timestamps
CREATE TABLE IF NOT EXISTS vouchers (
    id SERIAL PRIMARY KEY,
    code VARCHAR(32) UNIQUE NOT NULL,
    plan_name VARCHAR(64) NOT NULL,
    duration_seconds INT NOT NULL,
    price NUMERIC(10, 2) DEFAULT 0,
    status VARCHAR(16) NOT NULL DEFAULT 'unused' CHECK (status IN ('unused', 'active', 'expired')),
    created_at TIMESTAMP DEFAULT NOW(),
    first_used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_by VARCHAR(64)
);

-- Index for fast lookup by code
CREATE INDEX idx_vouchers_code ON vouchers(code);

-- Index for filtering by status
CREATE INDEX idx_vouchers_status ON vouchers(status);

-- Optional: Admin users table (single admin for v1)
CREATE TABLE IF NOT EXISTS admins (
    id SERIAL PRIMARY KEY,
    username VARCHAR(64) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Insert default admin (username: admin, password: admin123 - CHANGE THIS!)
-- Generate hash first: php -r "echo password_hash('admin123', PASSWORD_ARGON2ID);"
-- INSERT INTO admins (username, password_hash) VALUES ('admin', '$argon2id$v=19$m=65536$t=3$p=<hash>');

COMMENT ON TABLE vouchers IS 'WiFi voucher tracking for duration-based access control';
COMMENT ON COLUMN vouchers.code IS 'Voucher code - same as radcheck.username';
COMMENT ON COLUMN vouchers.status IS 'unused: not yet used, active: session started, expired: time used up';
COMMENT ON COLUMN vouchers.first_used_at IS 'When voucher was first used - duration timer starts here';
COMMENT ON COLUMN vouchers.expires_at IS 'Calculated expiry time = first_used_at + duration_seconds';
