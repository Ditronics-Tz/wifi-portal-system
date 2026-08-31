-- Migration 008: Align Acct-Interim-Interval with EAP650 minimum (60 s)
-- EAP650 accepts 60–86400 s only; 30 s in radreply is ignored by the AP.
-- Run: psql -U radius -d radius -f migrations/008_interim_interval_60.sql

UPDATE radreply
SET value = '60'
WHERE attribute = 'Acct-Interim-Interval'
  AND value::int < 60;
