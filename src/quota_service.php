<?php
/**
 * Data Quota Enforcement Service
 *
 * Three enforcement layers work together:
 *
 *  Layer 1 — FreeRADIUS sqlcounter_quota module (real-time, server-side):
 *    Reads Max-All-Octets from radcheck and rejects Access-Requests the moment
 *    cumulative bytes in radacct exceed the limit.  No PHP involvement needed.
 *    Requires the module to be configured (see nginx/freeradius-sqlcounter-quota).
 *
 *  Layer 2a — accounting hook (bin/quota_accounting_hook.php, via the
 *    `quota_hook` exec in nginx/freeradius-accounting-quota): runs on every
 *    Interim-Update/Stop, so an over-quota voucher is expired within seconds
 *    of the AP reporting usage instead of waiting for the cron sweep.
 *
 *  Layer 2b — PHP cron (this service via bin/enforce_quota.php, every 30s):
 *    Batched sweep over all active quota vouchers with one aggregated radacct
 *    query.  When a voucher is over quota it:
 *      - marks the voucher 'expired' in the DB first (re-auth is blocked even
 *        if the AP never answers CoA)
 *      - closes all PHP-tracked sessions
 *      - inserts Auth-Type=Reject into radcheck (belt-and-suspenders against
 *        reconnects if the sqlcounter module is not yet configured)
 *      - sends a fast CoA Disconnect packet to the AP (best-effort, 2s/1 retry)
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
 * Order matters: the DB is updated FIRST so re-auth is blocked even when the
 * AP never answers CoA. The disconnect goes last and uses the fast
 * non-blocking defaults (2s timeout, 1 retry) so a dead AP cannot stall the
 * cron sweep.
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
 * @param string $source    Where the expiry was triggered from (cron or accounting hook)
 * @param int    $usedBytes Precomputed byte total (avoids a second radacct SUM)
 */
function expireVoucherDueToQuota(string $code, int $voucherId, string $source = 'quota_enforcement_cron', int $usedBytes = 0): void {
    $db = getDB();

    // 1. Mark expired — end time immediately when MB limit is hit.
    //    Guarded by status='active' so concurrent cron + accounting-hook runs
    //    cannot double-expire (second caller becomes a no-op).
    $stmt = $db->prepare("
        UPDATE vouchers
        SET    status     = 'expired',
               expires_at = CURRENT_TIMESTAMP
        WHERE  id = :id
          AND  status = 'active'
    ");
    $stmt->execute([':id' => $voucherId]);
    if ($stmt->rowCount() === 0) {
        return;
    }

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

    // 4. Kick the live session last — best-effort with fast timeouts so one
    //    dead AP cannot stall the sweep over many vouchers.
    $disconnect = radius_disconnect($code);

    // 5. Security event
    if ($usedBytes <= 0) {
        $usedBytes = getVoucherBytesUsed($code);
    }
    recordSecurityEvent('QUOTA_EXCEEDED', 'medium', $code, null, [
        'source'          => $source,
        'used_bytes'      => $usedBytes,
        'used_mb'         => round($usedBytes / (1024 * 1024), 2),
        'disconnect_sent' => $disconnect['success'],
        'disconnect_msg'  => $disconnect['message'] ?? null,
    ]);

    error_log(sprintf(
        '[quota] Voucher %s expired — quota exceeded (%.2f MB used, source=%s)',
        $code,
        $usedBytes / (1024 * 1024),
        $source
    ));
}

/**
 * Fast single-voucher quota check for the accounting hook
 * (bin/quota_accounting_hook.php). Early-exits before touching radacct when
 * the voucher is not active or its package has no data cap.
 *
 * @return array{checked: bool, exceeded: bool, used_bytes: int, quota_bytes: int}
 */
function checkSingleVoucherQuota(string $code): array {
    $none = ['checked' => false, 'exceeded' => false, 'used_bytes' => 0, 'quota_bytes' => 0];
    if (!preg_match('/^[A-Za-z0-9]{1,64}$/', $code)) {
        return $none;
    }
    $db = getDB();
    try {
        $stmt = $db->prepare("
            SELECT v.id, v.status, COALESCE(p.data_quota_mb, 0)::int AS quota_mb
            FROM vouchers v
            LEFT JOIN packages p ON p.name = v.plan_name
                AND COALESCE(p.is_deleted, false) = false
            WHERE v.code = :code
            LIMIT 1
        ");
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();
        if (!$row || $row['status'] !== 'active' || (int) $row['quota_mb'] <= 0) {
            return $none;
        }
        $quotaBytes = (int) $row['quota_mb'] * 1024 * 1024;
        $usedBytes = getVoucherBytesUsed($code);
        if ($usedBytes > $quotaBytes) {
            expireVoucherDueToQuota($code, (int) $row['id'], 'quota_accounting_hook', $usedBytes);
            return ['checked' => true, 'exceeded' => true, 'used_bytes' => $usedBytes, 'quota_bytes' => $quotaBytes];
        }
        return ['checked' => true, 'exceeded' => false, 'used_bytes' => $usedBytes, 'quota_bytes' => $quotaBytes];
    } catch (Exception $e) {
        error_log('[quota] checkSingleVoucherQuota(' . $code . '): ' . $e->getMessage());
        return $none;
    }
}

/**
 * Quota health signals for bin/verify_quota_setup.php and future monitoring:
 * stale interim accounting (open sessions not updated for >90s) and the
 * 24h CoA failure rate from QUOTA_EXCEEDED events.
 *
 * @return array{stale_interim: int, open_sessions: int, coa_fail_24h: int, coa_total_24h: int}
 */
function getQuotaHealth(): array {
    $health = ['stale_interim' => 0, 'open_sessions' => 0, 'coa_fail_24h' => 0, 'coa_total_24h' => 0];
    $db = getDB();
    try {
        $row = $db->query("
            SELECT COUNT(*) AS open,
                   COUNT(*) FILTER (WHERE acctupdatetime < NOW() - INTERVAL '90 seconds') AS stale
            FROM radacct
            WHERE acctstoptime IS NULL
        ")->fetch();
        if ($row) {
            $health['open_sessions'] = (int) ($row['open'] ?? 0);
            $health['stale_interim'] = (int) ($row['stale'] ?? 0);
        }
    } catch (Exception $e) {
        // radacct optional — leave zeros
    }
    try {
        $row = $db->query("
            SELECT COUNT(*) AS total,
                   COUNT(*) FILTER (WHERE metadata->>'disconnect_sent' = 'false') AS failed
            FROM security_events
            WHERE event_type = 'QUOTA_EXCEEDED'
              AND created_at > NOW() - INTERVAL '24 hours'
        ")->fetch();
        if ($row) {
            $health['coa_total_24h'] = (int) ($row['total'] ?? 0);
            $health['coa_fail_24h'] = (int) ($row['failed'] ?? 0);
        }
    } catch (Exception $e) {
        // security_events optional — leave zeros
    }
    return $health;
}

// ── Main Enforcement Loop ───────────────────────────────────────

/**
 * Check every active voucher that has a data-quota package and expire those
 * that have exceeded their allowance.  Safe to run repeatedly; skips vouchers
 * whose package has no quota set.
 *
 * Batched: ONE aggregated radacct query returns byte totals for all candidate
 * vouchers (instead of one SUM per voucher), usage cache updates are batched
 * per voucher only when the value changed, and DB expiry for all over-quota
 * vouchers lands before any slow CoA packet goes out.
 *
 * Called by bin/enforce_quota.php (cron, recommended every 30 seconds).
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

    // One query: active quota vouchers + lifetime byte totals + live-session flag.
    try {
        $stmt = $db->query("
            SELECT v.id, v.code, v.plan_name,
                   COALESCE(p.data_quota_mb, 0)::int AS quota_mb,
                   COALESCE(ra.total_bytes, 0)::bigint AS used_bytes,
                   EXISTS (
                       SELECT 1 FROM radacct r
                       WHERE r.username = v.code AND r.acctstoptime IS NULL
                   ) AS has_live
            FROM   vouchers v
            INNER JOIN packages p ON p.name = v.plan_name
                AND COALESCE(p.data_quota_mb, 0) > 0
                AND COALESCE(p.is_deleted, false) = false
            LEFT JOIN (
                SELECT username, SUM(acctinputoctets + acctoutputoctets)::bigint AS total_bytes
                FROM radacct
                GROUP BY username
            ) ra ON ra.username = v.code
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
        $candidates = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log('[quota] enforcement batch query failed: ' . $e->getMessage());
        return ['checked' => 0, 'expired' => 0, 'errors' => 1];
    }

    $checked = 0;
    $expired = 0;
    $errors  = 0;
    $cacheStmt = $db->prepare(
        "UPDATE vouchers SET data_bytes_used = :b1 WHERE id = :id AND COALESCE(data_bytes_used, 0) != :b2"
    );

    foreach ($candidates as $voucher) {
        try {
            $quotaMb = (int) ($voucher['quota_mb'] ?? 0);
            if ($quotaMb <= 0) {
                continue; // Package has no data cap — skip
            }
            $checked++;
            $usedBytes = (int) ($voucher['used_bytes'] ?? 0);

            // Cache the byte count so the status page avoids a radacct join.
            // Guarded by != so idle vouchers cost no write.
            $cacheStmt->execute([':b1' => $usedBytes, ':b2' => $usedBytes, ':id' => (int) $voucher['id']]);

            if ($usedBytes > $quotaMb * 1024 * 1024) {
                expireVoucherDueToQuota(
                    $voucher['code'],
                    (int) $voucher['id'],
                    'quota_enforcement_cron',
                    $usedBytes
                );
                $expired++;
            }
        } catch (Exception $e) {
            $errors++;
            error_log('[quota] Error on voucher ' . ($voucher['code'] ?? '?') . ': ' . $e->getMessage());
        }
    }

    return ['checked' => $checked, 'expired' => $expired, 'errors' => $errors];
}
