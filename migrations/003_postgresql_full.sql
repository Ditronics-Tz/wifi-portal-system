-- Migration 003: Full PostgreSQL schema
-- Run: psql -U radius -d radius -f migrations/003_postgresql_full.sql

BEGIN;

-- Vouchers
CREATE TABLE IF NOT EXISTS vouchers (
    id SERIAL PRIMARY KEY,
    code VARCHAR(32) UNIQUE NOT NULL,
    plan_name VARCHAR(64) NOT NULL,
    duration_seconds INT NOT NULL,
    price NUMERIC(10,2) DEFAULT 0,
    status VARCHAR(16) NOT NULL DEFAULT 'unused' CHECK (status IN ('unused','active','expired')),
    created_at TIMESTAMP DEFAULT NOW(),
    first_used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_by VARCHAR(64),
    seller_id INTEGER NULL
);
CREATE INDEX IF NOT EXISTS idx_vouchers_code ON vouchers(code);
CREATE INDEX IF NOT EXISTS idx_vouchers_status ON vouchers(status);
CREATE INDEX IF NOT EXISTS idx_vouchers_seller_id ON vouchers(seller_id);

-- FreeRADIUS tables
CREATE TABLE IF NOT EXISTS radcheck (
    id SERIAL PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    attribute VARCHAR(64) NOT NULL,
    op VARCHAR(2) NOT NULL DEFAULT ':=',
    value VARCHAR(253) NOT NULL
);
CREATE TABLE IF NOT EXISTS radreply (
    id SERIAL PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    attribute VARCHAR(64) NOT NULL,
    op VARCHAR(2) NOT NULL DEFAULT ':=',
    value VARCHAR(253) NOT NULL
);

-- Users
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    role VARCHAR(16) NOT NULL CHECK (role IN ('admin', 'seller', 'buyer')),
    username VARCHAR(64) UNIQUE NOT NULL,
    phone VARCHAR(20) UNIQUE,
    full_name VARCHAR(128),
    password_hash VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT true,
    is_deleted BOOLEAN DEFAULT false,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    created_by INT NULL REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
CREATE INDEX IF NOT EXISTS idx_users_is_active ON users(is_active);

-- Sales
CREATE TABLE IF NOT EXISTS sales (
    id SERIAL PRIMARY KEY,
    voucher_code VARCHAR(32) NOT NULL,
    seller_id INT NULL REFERENCES users(id),
    buyer_id INT NULL REFERENCES users(id),
    buyer_phone VARCHAR(20) NULL,
    buyer_name VARCHAR(128) NULL,
    plan_name VARCHAR(64) NOT NULL,
    price NUMERIC(10,2) NOT NULL,
    payment_method VARCHAR(16) DEFAULT 'cash',
    payment_reference VARCHAR(64) NULL,
    sold_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_sales_seller_id ON sales(seller_id);
CREATE INDEX IF NOT EXISTS idx_sales_voucher_code ON sales(voucher_code);
CREATE INDEX IF NOT EXISTS idx_sales_sold_at ON sales(sold_at);

-- Packages
CREATE TABLE IF NOT EXISTS packages (
    id SERIAL PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    slug VARCHAR(64) UNIQUE NOT NULL,
    duration_seconds INT NOT NULL,
    price NUMERIC(10,2) NOT NULL,
    bandwidth_mbps INT NULL,
    data_quota_mb INT NULL,
    description TEXT NULL,
    is_active BOOLEAN DEFAULT true,
    is_deleted BOOLEAN DEFAULT false,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    created_by INT NULL REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_packages_slug ON packages(slug);
CREATE INDEX IF NOT EXISTS idx_packages_is_active ON packages(is_active);

-- Audit Log
CREATE TABLE IF NOT EXISTS audit_log (
    id SERIAL PRIMARY KEY,
    user_id INT NULL REFERENCES users(id),
    action VARCHAR(64) NOT NULL,
    entity_type VARCHAR(32),
    entity_id VARCHAR(64),
    details JSONB,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_audit_log_user_id ON audit_log(user_id);
CREATE INDEX IF NOT EXISTS idx_audit_log_action ON audit_log(action);
CREATE INDEX IF NOT EXISTS idx_audit_log_created_at ON audit_log(created_at);

-- Seed default packages
INSERT INTO packages (slug, name, duration_seconds, price, description, sort_order)
VALUES
    ('siku_1', 'Siku 1', 86400, 500, 'Mtandao kwa saa 24', 1),
    ('wiki_1', 'Wiki 1', 604800, 3000, 'Mtandao kwa siku 7', 2),
    ('mwezi_1', 'Mwezi 1', 2592000, 10000, 'Mtandao kwa siku 30', 3)
ON CONFLICT (slug) DO NOTHING;

COMMIT;
