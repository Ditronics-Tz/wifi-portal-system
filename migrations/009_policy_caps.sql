-- Migration 009: cap all durations at the 30-day policy ceiling
-- Run: psql -U radius -d radius -f migrations/009_policy_caps.sql
--   (from the server: sudo -u postgres psql -d radius -f /var/www/voucher-portal/migrations/009_policy_caps.sql)
--
-- Policy: every voucher carries an MB cap AND a lifetime of at most 30 days
-- from first use (standard tiers: 3 days / 1 week / 2 weeks / 4 weeks).
-- New rows are enforced in PHP (package validation, clamps at generation and
-- first use); this migration repairs rows created before that:
--
--  1. Packages longer than 30 days are shortened (future vouchers inherit it).
--  2. Unused vouchers longer than 30 days are shortened (not yet sold/used).
--  3. Active vouchers expiring more than 30 days after first use are pulled
--     back to first_use + 30 days. NOTE: this shortens already-sold sessions.
--     Skip statement 3 to grandfather current customers.
--
-- Data quotas are NOT backfilled here: MB allowances are a business decision
-- per package. Packages without data_quota_mb cannot generate new vouchers
-- (generateVouchers throws) until the admin sets an MB limit. Find them with:
--   SELECT name FROM packages WHERE COALESCE(data_quota_mb, 0) < 1 AND COALESCE(is_deleted, false) = false;

BEGIN;

-- ── 1. Clamp package durations to 30 days ──────────────────────────
UPDATE packages
SET    duration_seconds = 2592000,
       updated_at = CURRENT_TIMESTAMP
WHERE  duration_seconds > 2592000
  AND  COALESCE(is_deleted, false) = false;

-- ── 2. Clamp unused voucher durations ──────────────────────────────
UPDATE vouchers
SET    duration_seconds = 2592000
WHERE  status = 'unused'
  AND  duration_seconds > 2592000;

-- ── 3. Pull back active vouchers past first_use + 30 days ──────────
UPDATE vouchers
SET    expires_at = first_used_at + INTERVAL '30 days'
WHERE  status = 'active'
  AND  first_used_at IS NOT NULL
  AND  expires_at > first_used_at + INTERVAL '30 days';

COMMIT;

-- ── Verification ───────────────────────────────────────────────────
--   SELECT name, duration_seconds FROM packages WHERE duration_seconds > 2592000;
--   -- Expected: 0 rows
--   SELECT COUNT(*) FROM vouchers WHERE status = 'unused' AND duration_seconds > 2592000;
--   -- Expected: 0
--   SELECT code, first_used_at, expires_at FROM vouchers
--   WHERE status = 'active' AND expires_at > first_used_at + INTERVAL '30 days';
--   -- Expected: 0 rows
--   SELECT name FROM packages
--   WHERE COALESCE(data_quota_mb, 0) < 1 AND COALESCE(is_deleted, false) = false;
--   -- Packages still needing an MB limit before they can generate vouchers
