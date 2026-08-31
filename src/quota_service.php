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
require_once __DIR__ . '/voucher_service.php';

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

function bytesToMb(int $bytes): float {
    return round($bytes / (1024 * 1024), 2);
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
    $rawPercent = $quotaBytes > 0 ? round(($usedBytes / $quotaBytes) * 100, 1) : 0.0;
    $isOver     = $usedBytes > $quotaBytes;

    return [
        'has_quota'       => true,
        'quota_mb'        => (int) $pkg['data_quota_mb'],
        'quota_bytes'     => $quotaBytes,
        'used_bytes'      => $usedBytes,
        'used_mb'         => round($usedBytes  / (1024 * 1024), 2),
        'remaining_bytes' => $remaining,
        'remaining_mb'    => round($remaining  / (1024 * 1024), 2),
        'exceeded'        => $isOver,
        'exceeded_by_mb'  => $isOver ? round(($usedBytes - $quotaBytes) / (1024 * 1024), 2) : 0.0,
        'percent_used'    => $rawPercent,
        'display_percent' => min(100.0, $rawPercent),
        'is_over_quota'   => $isOver,
    ];
}

// ── Sold voucher usage (admin) ──────────────────────────────────

/**
 * @return array{sql: string, params: array<string, mixed>}
 */
function buildSoldVoucherUsageFilters(
    ?string $dateFrom = null,
    ?string $dateTo = null,
    ?string $planName = null,
    ?string $status = null,
    ?string $search = null
): array {
    $sql = ' WHERE 1=1';
    $params = [];

    if ($dateFrom) {
        $sql .= ' AND s.sold_at >= :date_from';
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo) {
        $sql .= ' AND s.sold_at <= :date_to';
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }
    if ($planName) {
        $sql .= ' AND s.plan_name = :plan_name';
        $params[':plan_name'] = $planName;
    }
    if ($status) {
        $sql .= ' AND v.status = :status';
        $params[':status'] = $status;
    }
    if ($search) {
        $sql .= ' AND (s.voucher_code LIKE :search OR s.buyer_phone LIKE :search OR s.buyer_name LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    return ['sql' => $sql, 'params' => $params];
}

function enrichSoldVoucherUsageRow(array $row): array {
    $usedBytes = max((int) ($row['data_bytes_used'] ?? 0), (int) ($row['radacct_bytes'] ?? 0));
    $quotaMb = (int) ($row['quota_mb'] ?? 0);
    $quotaBytes = $quotaMb > 0 ? $quotaMb * 1024 * 1024 : 0;
    $remainingBytes = $quotaBytes > 0 ? max(0, $quotaBytes - $usedBytes) : 0;
    $rawPercent = $quotaBytes > 0 ? round(($usedBytes / $quotaBytes) * 100, 1) : null;

    $row['used_bytes'] = $usedBytes;
    $row['used_mb'] = bytesToMb($usedBytes);
    $row['remaining_mb'] = $quotaBytes > 0 ? bytesToMb($remainingBytes) : null;
    $row['has_quota'] = $quotaMb > 0;
    $row['percent_used'] = $rawPercent;
    $row['display_percent'] = $rawPercent !== null ? min(100.0, $rawPercent) : null;
    $row['is_over_quota'] = $quotaBytes > 0 && $usedBytes > $quotaBytes;
    $row['exceeded_by_mb'] = $row['is_over_quota'] ? bytesToMb($usedBytes - $quotaBytes) : 0.0;

    return $row;
}

/**
 * Sold vouchers with data usage for the admin usage page.
 */
function getSoldVoucherUsage(
    ?string $dateFrom = null,
    ?string $dateTo = null,
    ?string $planName = null,
    ?string $status = null,
    ?string $search = null,
    int $limit = 25,
    int $offset = 0
): array {
    $db = getDB();
    $filters = buildSoldVoucherUsageFilters($dateFrom, $dateTo, $planName, $status, $search);

    $sql = "
        SELECT
            s.id,
            s.voucher_code,
            s.plan_name,
            s.buyer_name,
            s.buyer_phone,
            s.sold_at,
            s.price,
            u.username AS seller_username,
            v.status AS voucher_status,
            v.first_used_at,
            v.expires_at,
            COALESCE(v.data_bytes_used, 0) AS data_bytes_used,
            COALESCE(p.data_quota_mb, 0) AS quota_mb,
            COALESCE(ra.total_bytes, 0) AS radacct_bytes
        FROM sales s
        LEFT JOIN vouchers v ON v.code = s.voucher_code
        LEFT JOIN packages p ON p.name = s.plan_name AND COALESCE(p.is_deleted, false) = false
        LEFT JOIN users u ON u.id = s.seller_id
        LEFT JOIN (
            SELECT username, SUM(acctinputoctets + acctoutputoctets)::bigint AS total_bytes
            FROM radacct
            GROUP BY username
        ) ra ON ra.username = s.voucher_code
        {$filters['sql']}
        ORDER BY s.sold_at DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $db->prepare($sql);
    foreach ($filters['params'] as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return array_map('enrichSoldVoucherUsageRow', $stmt->fetchAll());
}

function countSoldVoucherUsage(
    ?string $dateFrom = null,
    ?string $dateTo = null,
    ?string $planName = null,
    ?string $status = null,
    ?string $search = null
): int {
    $db = getDB();
    $filters = buildSoldVoucherUsageFilters($dateFrom, $dateTo, $planName, $status, $search);

    $sql = "
        SELECT COUNT(*)
        FROM sales s
        LEFT JOIN vouchers v ON v.code = s.voucher_code
        LEFT JOIN users u ON u.id = s.seller_id
        {$filters['sql']}
    ";

    $stmt = $db->prepare($sql);
    foreach ($filters['params'] as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    return (int) $stmt->fetchColumn();
}

/**
 * Summary stats for the admin usage page.
 */
function getSoldVoucherUsageStats(
    ?string $dateFrom = null,
    ?string $dateTo = null,
    ?string $planName = null,
    ?string $status = null,
    ?string $search = null
): array {
    $db = getDB();
    $filters = buildSoldVoucherUsageFilters($dateFrom, $dateTo, $planName, $status, $search);

    $sql = "
        SELECT
            COUNT(*) AS sold_count,
            COUNT(*) FILTER (WHERE v.status = 'active') AS active_count,
            COUNT(*) FILTER (WHERE GREATEST(COALESCE(v.data_bytes_used, 0), COALESCE(ra.total_bytes, 0)) > 0) AS used_count,
            COALESCE(SUM(GREATEST(COALESCE(v.data_bytes_used, 0), COALESCE(ra.total_bytes, 0))), 0) AS total_bytes
        FROM sales s
        LEFT JOIN vouchers v ON v.code = s.voucher_code
        LEFT JOIN users u ON u.id = s.seller_id
        LEFT JOIN (
            SELECT username, SUM(acctinputoctets + acctoutputoctets)::bigint AS total_bytes
            FROM radacct
            GROUP BY username
        ) ra ON ra.username = s.voucher_code
        {$filters['sql']}
    ";

    $stmt = $db->prepare($sql);
    foreach ($filters['params'] as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $row = $stmt->fetch() ?: [];

    return [
        'sold_count'   => (int) ($row['sold_count'] ?? 0),
        'active_count' => (int) ($row['active_count'] ?? 0),
        'used_count'   => (int) ($row['used_count'] ?? 0),
        'total_mb'     => bytesToMb((int) ($row['total_bytes'] ?? 0)),
    ];
}

// ── Enforcement ─────────────────────────────────────────────────

/**
 * Expire a voucher because it has exceeded its data quota.
 *
 * Steps:
 *  1. Mark voucher 'expired' in DB and force expires_at = now (time ends with quota)
 *  2. Close all active PHP-tracked sessions (reason: quota_exceeded)
 *  3. Auth-Type=Reject + Session-Timeout=1 so the next AP re-auth is denied/ended
 *  4. Send CoA Disconnect to the NAS (AP) — best-effort, logged on failure
 *  5. Record QUOTA_EXCEEDED security event
 *
 * Pair with EAP650 portal Authentication Timeout = 1 minute so over-quota
 * clients re-auth quickly and lose access without waiting for the original day timer.
 *
 * @param string $code      Voucher code
 * @param int    $voucherId Row ID from the vouchers table
 */
function expireVoucherDueToQuota(string $code, int $voucherId): void {
    $db = getDB();

    // 0. Kick live session from AP while radacct row may still be open
    $disconnect = radius_disconnect($code);

    // 1. Mark expired — end time immediately when MB limit is hit
    $stmt = $db->prepare("
        UPDATE vouchers
        SET    status     = 'expired',
               expires_at = CURRENT_TIMESTAMP
        WHERE  id = :id
          AND  status = 'active'
    ");
    $stmt->execute([':id' => $voucherId]);

    // 2. Close PHP sessions
    closeVoucherSessions($voucherId, 'quota_exceeded', 'blocked');

    // 3. Reject next auth; Session-Timeout=1 if Accept somehow slips through
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
    upsertRadAttribute('radreply', $code, 'Session-Timeout', '1');

    // 4. CoA already attempted above; log result below
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

    // Active vouchers with a data-capped package, including any with a live radacct row
    $stmt = $db->query("
        SELECT v.id, v.code, v.plan_name
        FROM   vouchers v
        INNER JOIN packages p ON p.name = v.plan_name
            AND COALESCE(p.data_quota_mb, 0) > 0
            AND COALESCE(p.is_deleted, false) = false
        WHERE  v.status = 'active'
          AND (
              v.expires_at > NOW()
              OR EXISTS (
                  SELECT 1 FROM radacct r
                  WHERE r.username = v.code AND r.acctstoptime IS NULL
              )
          )
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
