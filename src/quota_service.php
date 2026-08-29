<?php
/**
 * Data Quota Enforcement Service
 *
 * Two enforcement layers work together:
 *
 *  Layer 1 — FreeRADIUS sqlcounter_quota module (real-time, server-side):
 *    Reads Max-All-Octets from radcheck and rejects Access-Requests the moment
 *    cumulative bytes in radacct exceed the limit.  No PHP involvement needed.
 *    Requires the module to be configured (see nginx/freeradius-sqlcounter-quota).
 *
 *  Layer 2 — PHP cron (this service via bin/enforce_quota.php, every 1 min):
 *    Polls radacct directly.  When a voucher is over quota it:
 *      - marks the voucher 'expired' in the DB
 *      - closes all PHP-tracked sessions
 *      - inserts Auth-Type=Reject into radcheck (belt-and-suspenders against
 *        reconnects if the sqlcounter module is not yet configured)
 *      - sends a CoA Disconnect packet to the AP to terminate the live session
 *      - logs a QUOTA_EXCEEDED security event
 *
 * Requires:
 *  - FreeRADIUS accounting enabled on the AP and in FreeRADIUS
 *  - Acct-Interim-Interval=60 in radreply (set by applyVoucherRadiusPolicy)
 *    so radacct byte counts are refreshed every 60 s, not just at session end
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session_service.php';
require_once __DIR__ . '/package_service.php';
require_once __DIR__ . '/radius_client.php';

// ── Byte Usage ──────────────────────────────────────────────────

/**
 * Return total bytes (upload + download) consumed by a voucher code
 * across ALL its RADIUS sessions, including closed ones.
 *
 * FreeRADIUS writes Acct-Input-Octets and Acct-Output-Octets to radacct:
 *  - at session start (0 bytes)
 *  - on every interim accounting update (every Acct-Interim-Interval seconds)
 *  - at session stop (final count)
 *
 * @param string $code Voucher code (== RADIUS username)
 * @return int Total bytes; 0 when radacct is unavailable or has no rows
 */
function getVoucherBytesUsed(string $code): int {
    $db = getDB();
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(acctinputoctets + acctoutputoctets), 0)
            FROM   radacct
            WHERE  username = :code
        ");
        $stmt->execute([':code' => $code]);
        return (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log('[quota] getVoucherBytesUsed(' . $code . '): ' . $e->getMessage());
        return 0;
    }
}

// ── Quota Status ────────────────────────────────────────────────

/**
 * Return a rich quota-status array for a voucher.
 *
 * @param string $code     Voucher code
 * @param string $planName Package/plan name (used to look up data_quota_mb)
 * @return array {
 *   has_quota:       bool   — false when the package has no data cap
 *   quota_mb:        int
 *   quota_bytes:     int
 *   used_bytes:      int
 *   used_mb:         float  (2 decimal places)
 *   remaining_bytes: int    (clamped to 0)
 *   remaining_mb:    float  (2 decimal places, clamped to 0)
 *   exceeded:        bool
 *   percent_used:    float  (0-100, 1 decimal place)
 * }
 */
function getVoucherQuotaStatus(string $code, string $planName): array {
    $pkg = getPackageByName($planName);
    if (!$pkg || empty($pkg['data_quota_mb']) || (int) $pkg['data_quota_mb'] <= 0) {
        return ['has_quota' => false];
    }

    $quotaBytes = (int) $pkg['data_quota_mb'] * 1024 * 1024;
    $usedBytes  = getVoucherBytesUsed($code);
    $remaining  = max(0, $quotaBytes - $usedBytes);

    return [
        'has_quota'       => true,
        'quota_mb'        => (int) $pkg['data_quota_mb'],
        'quota_bytes'     => $quotaBytes,
        'used_bytes'      => $usedBytes,
        'used_mb'         => round($usedBytes  / (1024 * 1024), 2),
        'remaining_bytes' => $remaining,
        'remaining_mb'    => round($remaining  / (1024 * 1024), 2),
        'exceeded'        => ($usedBytes >= $quotaBytes),
        'percent_used'    => min(100.0, $quotaBytes > 0
                                ? round(($usedBytes / $quotaBytes) * 100, 1)
                                : 0.0),
    ];
}

// ── Enforcement ─────────────────────────────────────────────────

/**
 * Expire a voucher because it has exceeded its data quota.
 *
 * Steps:
 *  1. Mark voucher 'expired' in DB
 *  2. Close all active PHP-tracked sessions (reason: quota_exceeded)
 *  3. Insert/update radcheck Auth-Type=Reject so FreeRADIUS denies reconnects
 *     even if the sqlcounter module is not installed
 *  4. Send CoA Disconnect to the NAS (AP) — best-effort, logged on failure
 *  5. Record QUOTA_EXCEEDED security event
 *
 * @param string $code      Voucher code
 * @param int    $voucherId Row ID from the vouchers table
 */
function expireVoucherDueToQuota(string $code, int $voucherId): void {
    $db = getDB();

    // 1. Mark expired
    $stmt = $db->prepare("
        UPDATE vouchers
        SET    status     = 'expired',
               expires_at = COALESCE(expires_at, CURRENT_TIMESTAMP)
        WHERE  id = :id
          AND  status = 'active'
    ");
    $stmt->execute([':id' => $voucherId]);

    // 2. Close PHP sessions
    closeVoucherSessions($voucherId, 'quota_exceeded', 'blocked');

    // 3. Force FreeRADIUS to reject future auth for this code
    $stmt = $db->prepare(
        "SELECT id FROM radcheck WHERE username = :u AND attribute = 'Auth-Type'"
    );
    $stmt->execute([':u' => $code]);
    if ($stmt->fetch()) {
        $db->prepare(
            "UPDATE radcheck SET value = 'Reject', op = ':='
             WHERE username = :u AND attribute = 'Auth-Type'"
        )->execute([':u' => $code]);
    } else {
        $db->prepare(
            "INSERT INTO radcheck (username, attribute, op, value)
             VALUES (:u, 'Auth-Type', ':=', 'Reject')"
        )->execute([':u' => $code]);
    }

    // 4. CoA Disconnect (best-effort — AP firmware may not respond)
    $disconnect = radius_disconnect($code);

    // 5. Security event
    $usedBytes = getVoucherBytesUsed($code);
    recordSecurityEvent('QUOTA_EXCEEDED', 'medium', $code, null, [
        'source'          => 'quota_enforcement_cron',
        'used_bytes'      => $usedBytes,
        'used_mb'         => round($usedBytes / (1024 * 1024), 2),
        'disconnect_sent' => $disconnect['success'],
        'disconnect_msg'  => $disconnect['message'] ?? null,
    ]);

    error_log(sprintf(
        '[quota] Voucher %s expired — quota exceeded (%.2f MB used)',
        $code,
        $usedBytes / (1024 * 1024)
    ));
}

// ── Main Enforcement Loop ───────────────────────────────────────

/**
 * Check every active voucher that has a data-quota package and expire those
 * that have exceeded their allowance.  Safe to run repeatedly; skips vouchers
 * whose package has no quota set.
 *
 * Called by bin/enforce_quota.php (cron, recommended every 1 minute).
 *
 * @return array{checked: int, expired: int, errors: int}
 */
function runQuotaEnforcement(): array {
    $db = getDB();

    // Bail gracefully if FreeRADIUS accounting is not enabled
    try {
        $db->query("SELECT 1 FROM radacct LIMIT 1");
    } catch (Exception $e) {
        error_log('[quota] radacct not available — is FreeRADIUS accounting enabled? ' . $e->getMessage());
        return ['checked' => 0, 'expired' => 0, 'errors' => 1];
    }

    // All active vouchers that have not yet expired by time
    $stmt = $db->query("
        SELECT v.id, v.code, v.plan_name
        FROM   vouchers v
        WHERE  v.status     = 'active'
          AND  v.expires_at > NOW()
        ORDER  BY v.id
    ");

    $checked = 0;
    $expired = 0;
    $errors  = 0;

    while ($voucher = $stmt->fetch()) {
        try {
            $qs = getVoucherQuotaStatus($voucher['code'], $voucher['plan_name']);

            if (!$qs['has_quota']) {
                continue; // Package has no data cap — skip
            }

            $checked++;

            // Cache the current byte count back onto the voucher row so the
            // status page can display usage without querying radacct each load.
            $db->prepare(
                "UPDATE vouchers SET data_bytes_used = :bytes WHERE id = :id"
            )->execute([':bytes' => $qs['used_bytes'], ':id' => (int) $voucher['id']]);

            if ($qs['exceeded']) {
                expireVoucherDueToQuota($voucher['code'], (int) $voucher['id']);
                $expired++;
            }
        } catch (Exception $e) {
            $errors++;
            error_log('[quota] Error on voucher ' . $voucher['code'] . ': ' . $e->getMessage());
        }
    }

    return ['checked' => $checked, 'expired' => $expired, 'errors' => $errors];
}
