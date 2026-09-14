-- Migration 010: seed standard tier packages
-- Run: psql -U radius -d radius -f migrations/010_seed_tier_packages.sql
--   (from the server: sudo -u postgres psql -d radius -f /var/www/voucher-portal/migrations/010_seed_tier_packages.sql)
--
-- Standard tiers (all under the 30-day policy ceiling):
--   TSH 500   = 1000 MB  / 3 days  (Siku 3)
--   TSH 1000  = 2000 MB  / 1 week  (Wiki 1)
--   TSH 5000  = 10000 MB / 2 weeks (Wiki 2)
--   TSH 10000 = 20000 MB / 4 weeks (Mwezi 1)
--
-- Idempotent: existing names are left untouched, so re-running is safe.
-- Old packages are NOT deactivated here — vouchers reference packages by
-- name, and deactivation is a business call. To hide a legacy package from
-- sellers after this runs:
--   UPDATE packages SET is_active = false WHERE name = 'Siku 1';

BEGIN;

INSERT INTO packages (name, duration_seconds, price, bandwidth_mbps, data_quota_mb, description, is_active, sort_order)
SELECT 'Siku 3', 259200, 500, NULL, 1000, 'MB 1000 kwa siku 3', true, 10
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name = 'Siku 3' AND COALESCE(is_deleted, false) = false);

INSERT INTO packages (name, duration_seconds, price, bandwidth_mbps, data_quota_mb, description, is_active, sort_order)
SELECT 'Wiki 1', 604800, 1000, NULL, 2000, 'MB 2000 kwa siku 7', true, 20
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name = 'Wiki 1' AND COALESCE(is_deleted, false) = false);

INSERT INTO packages (name, duration_seconds, price, bandwidth_mbps, data_quota_mb, description, is_active, sort_order)
SELECT 'Wiki 2', 1209600, 5000, NULL, 10000, 'MB 10000 kwa siku 14', true, 30
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name = 'Wiki 2' AND COALESCE(is_deleted, false) = false);

INSERT INTO packages (name, duration_seconds, price, bandwidth_mbps, data_quota_mb, description, is_active, sort_order)
SELECT 'Mwezi 1', 2419200, 10000, NULL, 20000, 'MB 20000 kwa siku 28', true, 40
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name = 'Mwezi 1' AND COALESCE(is_deleted, false) = false);

COMMIT;

-- ── Verification ───────────────────────────────────────────────────
--   SELECT name, duration_seconds, price, data_quota_mb, is_active
--   FROM packages WHERE COALESCE(is_deleted, false) = false ORDER BY price;
