-- Migration 007: Data quota tracking columns + radcheck cleanup
-- Run: psql -U radius -d radius -f migrations/007_quota_tracking.sql
--
-- What this migration does:
--  1. Adds data_bytes_used to vouchers — updated by the quota cron so the
--     status page can show data usage without querying radacct each load.
--  2. Removes any leftover ChilliSpot-Max-Total-Octets rows from radreply
--     (written by the old applyVoucherRadiusPolicy; replaced by Max-All-Octets
--     in radcheck which the FreeRADIUS sqlcounter module reads).
--  3. Backfills Acct-Interim-Interval=60 into radreply for existing active
--     vouchers so accounting updates start flowing on their next reconnect.
--  4. Adds an index on security_events(event_type) for faster admin queries.

BEGIN;

-- ── 1. Add data_bytes_used cache column ──────────────────────────────────────
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE  table_name = 'vouchers' AND column_name = 'data_bytes_used'
    ) THEN
        ALTER TABLE vouchers
            ADD COLUMN data_bytes_used BIGINT NOT NULL DEFAULT 0;

        COMMENT ON COLUMN vouchers.data_bytes_used
            IS 'Cached byte counter updated by bin/enforce_quota.php. '
               'Source of truth is radacct; this column avoids a radacct '
               'join on every status-page load.';
    END IF;
END $$;

-- ── 2. Remove stale ChilliSpot-Max-Total-Octets rows ────────────────────────
-- These were written by the old applyVoucherRadiusPolicy() and are harmless
-- but confusing.  The TP-Link EAP650 ignores this attribute entirely.
DELETE FROM radreply
WHERE  attribute = 'ChilliSpot-Max-Total-Octets';

-- ── 3. Backfill Acct-Interim-Interval for existing active vouchers ───────────
-- New vouchers get this automatically via applyVoucherRadiusPolicy().
-- Existing active ones need a one-time insert so accounting starts
-- flowing on their next reconnect.
INSERT INTO radreply (username, attribute, op, value)
SELECT DISTINCT v.code, 'Acct-Interim-Interval', ':=', '60'
FROM   vouchers v
WHERE  v.status = 'active'
  AND  NOT EXISTS (
           SELECT 1 FROM radreply r
           WHERE  r.username  = v.code
             AND  r.attribute = 'Acct-Interim-Interval'
       );

-- ── 4. Index on security_events(event_type) ──────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_security_events_event_type
    ON security_events(event_type);

COMMIT;

-- ── Verification ──────────────────────────────────────────────────────────────
-- Confirm ChilliSpot rows are gone:
--   SELECT COUNT(*) FROM radreply WHERE attribute = 'ChilliSpot-Max-Total-Octets';
--   -- Expected: 0
--
-- Confirm Acct-Interim-Interval rows exist for active vouchers:
--   SELECT r.username, r.attribute, r.value
--   FROM   radreply r
--   JOIN   vouchers v ON v.code = r.username
--   WHERE  r.attribute = 'Acct-Interim-Interval' AND v.status = 'active'
--   LIMIT 10;
--
-- Confirm Max-All-Octets exists for vouchers with a data quota:
--   SELECT username, attribute, value FROM radcheck
--   WHERE  attribute = 'Max-All-Octets' LIMIT 10;
