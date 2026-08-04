-- Migration 002: Seller Role, Sales Tracking, and Audit Log
-- Run against the 'radius' PostgreSQL database AFTER 001_add_vouchers_table.sql
--
-- psql -U radius -d radius -f migrations/002_seller_role.sql

BEGIN;

-- ============================================================
-- 1. users table — Multi-role user accounts (admin, seller, buyer)
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id              SERIAL PRIMARY KEY,
    role            VARCHAR(16) NOT NULL CHECK (role IN ('admin', 'seller', 'buyer')),
    username        VARCHAR(64) UNIQUE NOT NULL,
    phone           VARCHAR(20) UNIQUE,
    full_name       VARCHAR(128),
    password_hash   VARCHAR(255) NOT NULL,
    is_active       BOOLEAN DEFAULT true,
    created_at      TIMESTAMP DEFAULT NOW(),
    updated_at      TIMESTAMP DEFAULT NOW(),
    created_by      INT REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_users_role      ON users(role);
CREATE INDEX IF NOT EXISTS idx_users_username  ON users(username);
CREATE INDEX IF NOT EXISTS idx_users_is_active ON users(is_active);

COMMENT ON TABLE  users         IS 'Multi-role user accounts: admin, seller, buyer';
COMMENT ON COLUMN users.role    IS 'User role: admin, seller, or buyer';
COMMENT ON COLUMN users.phone   IS 'Phone number (required for buyer, optional for seller)';
COMMENT ON COLUMN users.created_by IS 'Admin user ID who created this account';

-- ============================================================
-- 2. sales table — Records every voucher sale transaction
-- ============================================================
CREATE TABLE IF NOT EXISTS sales (
    id                SERIAL PRIMARY KEY,
    voucher_code      VARCHAR(32) NOT NULL,
    seller_id         INT NOT NULL REFERENCES users(id),
    buyer_id          INT NULL REFERENCES users(id),
    buyer_phone       VARCHAR(20) NULL,
    buyer_name        VARCHAR(128) NULL,
    plan_name         VARCHAR(64) NOT NULL,
    price             NUMERIC(10,2) NOT NULL,
    payment_method    VARCHAR(16) DEFAULT 'cash',
    payment_reference VARCHAR(64) NULL,
    sold_at           TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_sales_seller_id    ON sales(seller_id);
CREATE INDEX IF NOT EXISTS idx_sales_voucher_code ON sales(voucher_code);
CREATE INDEX IF NOT EXISTS idx_sales_sold_at      ON sales(sold_at);
CREATE INDEX IF NOT EXISTS idx_sales_buyer_phone  ON sales(buyer_phone);

COMMENT ON TABLE  sales                IS 'Records every voucher sale transaction';
COMMENT ON COLUMN sales.voucher_code   IS 'FK to vouchers.code';
COMMENT ON COLUMN sales.seller_id      IS 'Seller who performed the transaction';
COMMENT ON COLUMN sales.buyer_id       IS 'Buyer user ID (NULL for walk-in customers)';
COMMENT ON COLUMN sales.buyer_phone    IS 'Buyer phone captured at sale time (for walk-in tracking)';
COMMENT ON COLUMN sales.payment_method IS 'cash, mpesa, tigopesa — extensible for future payment methods';
COMMENT ON COLUMN sales.payment_reference IS 'Transaction ID for electronic payments (NULL for cash)';

-- ============================================================
-- 3. Add seller_id to vouchers table (traceability)
-- ============================================================
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'vouchers' AND column_name = 'seller_id'
    ) THEN
        ALTER TABLE vouchers ADD COLUMN seller_id INT REFERENCES users(id);
        CREATE INDEX idx_vouchers_seller_id ON vouchers(seller_id);
    END IF;
END $$;

COMMENT ON COLUMN vouchers.seller_id IS 'Seller who generated this voucher (NULL for admin-generated)';

-- ============================================================
-- 4. audit_log table — Security and compliance trail
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_log (
    id          SERIAL PRIMARY KEY,
    user_id     INT NULL REFERENCES users(id),
    action      VARCHAR(64) NOT NULL,
    entity_type VARCHAR(32),
    entity_id   VARCHAR(64),
    details     JSONB,
    ip_address  VARCHAR(45),
    created_at  TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_audit_log_user_id    ON audit_log(user_id);
CREATE INDEX IF NOT EXISTS idx_audit_log_action     ON audit_log(action);
CREATE INDEX IF NOT EXISTS idx_audit_log_created_at ON audit_log(created_at);

COMMENT ON TABLE  audit_log        IS 'Security audit trail for sensitive operations';
COMMENT ON COLUMN audit_log.action IS 'Action type: seller_created, sale_recorded, voucher_generated, etc.';

-- ============================================================
-- 5. Seed: Insert a default seller account (optional)
--    Username: seller1  Password: seller123
--    IMPORTANT: Change this password in production!
-- ============================================================
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM users WHERE username = 'seller1') THEN
        INSERT INTO users (role, username, full_name, password_hash, is_active)
        VALUES (
            'seller',
            'seller1',
            'Default Seller',
            '$argon2id$v=19$m=65536,t=4,p=1$c2FsdHNhbHRzYWx0$placeholder_hash_replace_me',
            true
        );
    END IF;
END $$;

COMMIT;

-- ============================================================
-- Verification queries (run after migration):
--
-- SELECT * FROM users;
-- SELECT * FROM sales;
-- SELECT id, code, seller_id FROM vouchers LIMIT 5;
-- SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 10;
-- ============================================================
