-- Migration 009: flatten all packages/vouchers to a fixed 30-day lifetime
-- Run: psql -U radius -d radius -f migrations/009_policy_caps.sql
--   (from the server: sudo -u postgres psql -d radius -f /var/www/voucher-portal/migrations/009_policy_caps.sql)
--
-- Policy: duration is no longer a choice — every package is exactly 30 days
-- and packages differ only by MB quota (and price/bandwidth). New rows are
-- enforced in PHP (forced 30 days on package create/update, clamps at
-- generation and first use); this migration flattens rows created before that:
--
--  1. ALL packages become 30 days (Siku/Wiki day/week tiers included).
--  2. ALL unused vouchers become 30 days (unsold stock, no customer impact).
--  3. ALL active vouchers get expires_at = first_use + 30 days. NOTE: this
--     EXTENDS short vouchers already sold (e.g. a 1-day Siku becomes 30 days).
--     Skip statement 3 to leave current sessions on their sold terms — new
--     vouchers are still 30 days going forward.
--
-- Data quotas are NOT backfilled here: MB allowances are a business decision
-- per package. Packages without data_quota_mb cannot generate new vouchers
-- (generateVouchers throws) until the admin sets an MB limit. Find them with:
--   SELECT name FROM packages WHERE COALESCE(data_quota_mb, 0) < 1 AND COALESCE(is_deleted, false) = false;

BEGIN;

-- ── 1. All packages become 30 days ─────────────────────────────────
UPDATE packages
SET    duration_seconds = 2592000,
       updated_at = CURRENT_TIMESTAMP
WHERE  duration_seconds != 2592000
  AND  COALESCE(is_deleted, false) = false;

-- ── 2. All unused vouchers become 30 days ──────────────────────────
UPDATE vouchers
SET    duration_seconds = 2592000
WHERE  status = 'unused'
  AND  duration_seconds != 2592000;

-- ── 3. All active vouchers expire at first_use + 30 days ───────────
UPDATE vouchers
SET    expires_at = first_used_at + INTERVAL '30 days'
WHERE  status = 'active'
  AND  first_used_at IS NOT NULL
  AND  expires_at != first_used_at + INTERVAL '30 days';

COMMIT;

-- ── Verification ───────────────────────────────────────────────────
--   SELECT name, duration_seconds FROM packages WHERE duration_seconds != 2592000;
--   -- Expected: 0 rows
--   SELECT COUNT(*) FROM vouchers WHERE status = 'unused' AND duration_seconds != 2592000;
--   -- Expected: 0
--   SELECT COUNT(*) FROM vouchers
--   WHERE status = 'active' AND first_used_at IS NOT NULL
--     AND expires_at != first_used_at + INTERVAL '30 days';
--   -- Expected: 0 rows
--   SELECT name FROM packages
--   WHERE COALESCE(data_quota_mb, 0) < 1 AND COALESCE(is_deleted, false) = false;
--   -- Packages still needing an MB limit before they can generate vouchers
