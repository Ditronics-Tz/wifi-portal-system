# Data quota enforcement

Packages with `data_quota_mb` set get a **lifetime cap** per voucher. Four layers work together:

| Layer | What it does | Where |
|-------|----------------|-------|
| **1. sqlcounter_quota** | Rejects new logins when cumulative `radacct` bytes ≥ `Max-All-Octets` | FreeRADIUS `authorize` |
| **2a. accounting hook** | On every Interim-Update/Stop: checks one voucher, expires it within seconds | FreeRADIUS `accounting` → `bin/quota_accounting_hook.php` |
| **2b. enforce_quota cron** | Every 30s batched sweep: one aggregated `radacct` query, DB expiry first, fast CoA | `bin/enforce_quota.php` |
| **3. AP interim accounting** | Updates byte counts on the open `radacct` row during a session | TP-Link EAP650 |

Without layer 3, usage may jump from 0 → full total only when the session stops, so layers 2a/2b cannot cut the session early. Without layer 2a, worst-case overshoot is `60s interim + 30s cron + re-auth delay`; with it, cutoff lands within seconds of the AP reporting usage.

## One-time server setup (FreeRADIUS sqlcounter)

On `192.168.100.100` as root:

```bash
sudo bash /var/www/voucher-portal/bin/install_freeradius_quota.sh
```

Verify a voucher over quota gets **Access-Reject**:

```bash
radtest OVER_QUOTA_CODE OVER_QUOTA_CODE 127.0.0.1 0 YOUR_RADIUS_SECRET
```

Check module loaded:

```bash
sudo freeradius -XC 2>&1 | grep -i sqlcounter_quota
```

## TP-Link EAP650 — interim accounting (required)

On the AP web UI (likely `http://192.168.100.101` — confirm NAS IP in `radacct`; `.133` may be outdated):

1. **Wireless Control** → your voucher SSID → **Advanced** / **RADIUS**.
2. **RADIUS accounting** → enabled, server `192.168.100.100`, port **1813**, same secret as auth.
3. **Interim accounting / Accounting Update** → enable, interval **60** seconds.
   - EAP650 valid range: **60–86400 s** (below 60 is rejected/ignored).
   - **60 s** is recommended — matches the portal `Acct-Interim-Interval` in `radreply`.
4. If there is no interim toggle, the AP may still honour `Acct-Interim-Interval` from FreeRADIUS `radreply` once accounting is enabled.

The portal writes `Acct-Interim-Interval := 60` for each voucher when it is first used or reconnected.

### Confirm interim updates reach FreeRADIUS

During an active session:

```bash
php /var/www/voucher-portal/bin/verify_quota_setup.php
```

Or in SQL:

```sql
SELECT username, acctstarttime, acctupdatetime,
       acctinputoctets + acctoutputoctets AS bytes
FROM radacct
WHERE acctstoptime IS NULL
ORDER BY acctupdatetime DESC;
```

`acctupdatetime` should advance every ~30–60s and `bytes` should increase **before** the session stops.

## CoA / Disconnect (kick when over quota)

`radius_disconnect()` sends to **`192.168.100.101:3799`** using `RADIUS_NAS_SECRET` in `config.php` (must match the AP RADIUS secret).

On EAP650, if CoA is supported, enable **RADIUS CoA** / **Disconnect** on the same secret.

Test from the portal host:

```bash
php /var/www/voucher-portal/bin/verify_quota_setup.php --coa-test USERNAME
```

If the AP does not reply with `Disconnect-ACK`, the portal still expires the voucher, sets `Auth-Type=Reject` and `Session-Timeout=1`. The station may stay on WiFi until the next portal re-auth.

### EAP650 Authentication Timeout = 1 minute (required for quota cut-off)

Without CoA, the AP only drops a live session after the original Session-Timeout or when it forces re-authentication.

On the EAP650 **External Portal / captive portal** settings for the voucher SSID:

1. Find **Authentication Timeout** (sometimes under portal / RADIUS / advanced).
2. Set it to **1 minute** (not hours/days).
3. Save and apply.

After MB quota is hit, the client is forced to re-auth within about **1 minute**, FreeRADIUS rejects (or accepts with 1s timeout), and access ends. Active vouchers also re-auth every minute — expect more RADIUS auth traffic.

## Accounting hook (real-time cutoff)

Installed by the same script (`quota_hook` exec in `accounting{}`). It calls
`bin/quota_accounting_hook.php <User-Name>` fire-and-forget (`wait = no`,
5s timeout) on every Interim-Update/Stop, so accounting responses to the AP
are never stalled. The hook early-exits on one indexed lookup for vouchers
that are not active or have no cap; concurrent cron + hook expiry is safe
(second caller is a no-op via `status='active'` guard).

## Cron (portal host, safety net)

`ditronics_kibada` crontab should include (every 30 seconds):

```
* * * * * /usr/bin/php /var/www/voucher-portal/bin/enforce_quota.php >> ~/logs/voucher-portal/quota.log 2>&1
* * * * * sleep 30 && /usr/bin/php /var/www/voucher-portal/bin/enforce_quota.php >> ~/logs/voucher-portal/quota.log 2>&1
```

The sweep uses one aggregated `radacct` query for all vouchers, skips
usage-cache writes when unchanged, expires in the DB before sending CoA,
and uses fast disconnects (2s timeout, 1 retry — see `RADIUS_DISCONNECT_TIMEOUT`
/ `RADIUS_DISCONNECT_RETRIES` in `config.php`).

Apply the performance indexes once:

```bash
psql -U radius -d radius -f migrations/008_quota_perf.sql
```

## Voucher policy: MB cap + one-month max on everything

Every voucher carries **both** limits, whichever hits first ends the session:

- **MB cap** — `data_quota_mb` is mandatory on packages (`validatePackageQuota`).
  Packages without one cannot generate vouchers until the admin sets an MB limit.
- **Max 30 days** — durations are validated at package create/update
  (`validatePackageDuration`, 60s–2592000s), clamped again at voucher
  generation and at first use, so an untouched MB allowance never extends a
  voucher past one month from first use. Standard tiers: 3 days / 1 week /
  2 weeks / 4 weeks.

Repair existing rows once with `migrations/009_policy_caps.sql` (clamps
over-long packages, unused vouchers, and active voucher expiry; statement 3
shortens already-sold sessions — skip it to grandfather current customers).
It does not invent MB values: list quota-less packages with the query in the
migration header and set each MB limit in the admin Packages page.

## Monitoring

`bin/verify_quota_setup.php` now also reports `quota_hook` install status,
stale interim sessions (open rows not updated for >90s ⇒ AP interim off),
and the 24h CoA failure rate from `QUOTA_EXCEEDED` events.

## Troubleshooting

| Symptom | Likely cause |
|---------|----------------|
| Usage jumps to full amount at session end only | AP not sending interim accounting |
| Usage far above quota before expire | Same + sqlcounter not installed |
| `disconnect_sent: false` in security events | CoA disabled on AP or wrong `RADIUS_NAS_SECRET` |
| Reconnect still works after quota | sqlcounter not in `authorize{}` or missing `Max-All-Octets` in `radcheck` |
