-- Migration 008: quota enforcement performance indexes
-- Run: psql -U radius -d radius -f migrations/008_quota_perf.sql
--
-- What this migration does:
--  1. Composite index on radacct(username, acctstoptime) — speeds the
--     per-voucher SUM(username) and the open-session EXISTS checks used by
--     runQuotaEnforcement(), checkSingleVoucherQuota() and the
--     sqlcounter_quota query.
--  2. Partial index on open radacct rows only — keeps the "live sessions"
--     scans (admin sessions page, syncSessionsFromRadacct) fast as history grows.
--  3. Index on vouchers(status, expires_at) — speeds the cron candidate scan.
--
-- radacct is owned by the FreeRADIUS schema; CREATE INDEX IF NOT EXISTS is
-- safe to re-run and takes a lightweight lock. On very large radacct tables
-- prefer CONCURRENTLY in a manual session; this file uses plain CREATE INDEX
-- to stay transaction-safe.

BEGIN;

CREATE INDEX IF NOT EXISTS idx_radacct_username_stop
    ON radacct (username, acctstoptime);

CREATE INDEX IF NOT EXISTS idx_radacct_open_updated
    ON radacct (acctupdatetime DESC)
    WHERE acctstoptime IS NULL;

CREATE INDEX IF NOT EXISTS idx_vouchers_status_expires
    ON vouchers (status, expires_at);

COMMIT;

-- ── Verification ───────────────────────────────────────────────────
--   SELECT indexname FROM pg_indexes
--   WHERE tablename IN ('radacct', 'vouchers')
--     AND indexname LIKE 'idx\_%';
--   -- Expected: idx_radacct_username_stop, idx_radacct_open_updated,
--   --           idx_vouchers_status_expires (+ earlier indexes)
