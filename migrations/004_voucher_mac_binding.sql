-- ============================================================
-- Migration 004: Bind vouchers to the first device that uses them
-- ============================================================
-- Adds vouchers.first_mac — the clientMac of the device that first
-- redeemed the voucher. Used to:
--   1. Block a different device from reusing the same code while active
--   2. Auto-resume the same device without re-entering the code
-- Admins can clear this via the "Release device" action for lost/broken
-- phone cases.

BEGIN;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'vouchers' AND column_name = 'first_mac'
    ) THEN
        ALTER TABLE vouchers ADD COLUMN first_mac VARCHAR(17) NULL;
        CREATE INDEX idx_vouchers_first_mac ON vouchers(first_mac);
    END IF;
END $$;

COMMENT ON COLUMN vouchers.first_mac IS 'clientMac of the device that first redeemed this voucher; NULL = unbound (admin-released or never used)';

COMMIT;
