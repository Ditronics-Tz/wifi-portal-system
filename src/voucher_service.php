<?php
/**
 * Voucher service — DB-only timer logic
 * AP handles RADIUS auth directly; PHP only prepares/updates DB state.
 */

require_once dirname(__DIR__) . '/src/db.php';
require_once dirname(__DIR__) . '/src/session_service.php';
require_once dirname(__DIR__) . '/src/package_service.php';
require_once dirname(__DIR__) . '/src/radius_client.php';

/**
 * Prepare voucher for AP authentication (DB-only, no RADIUS call).
 * Called BEFORE the auto-submit form sends credentials to AP.
 *
 * @param string      $code      Voucher code
 * @param string|null $clientMac Device MAC reported by the AP for this attempt
 * @param string|null $clientIp  Client IP if the portal can see it
 * @return array ['status' => 'ok'|'invalid'|'expired'|'in_use']
 */
function prepareVoucherForAuth(string $code, ?string $clientMac = null, ?string $clientIp = null): array {
    // Validate code format
    if (!preg_match(VOUCHER_CODE_PATTERN, $code)) {
        return ['status' => 'invalid'];
    }

    if ($clientMac) {
        $clientMac = normalizeMacAddress($clientMac);
    }

    $db = getDB();

    try {
        $db->beginTransaction();

        // Lock row to prevent race conditions
        // Note: FOR UPDATE is PostgreSQL-specific; SQLite handles concurrency via WAL mode
        $stmt = $db->prepare("
            SELECT id, code, plan_name, duration_seconds, status, first_used_at, expires_at, first_mac
            FROM vouchers
            WHERE code = :code
            FOR UPDATE
        ");
        $stmt->execute([':code' => $code]);
        $voucher = $stmt->fetch();

        // 1. Not found
        if (!$voucher) {
            $db->rollBack();
            recordSecurityEvent('INVALID_VOUCHER', 'low', $code, null, ['mac' => $clientMac]);
            return ['status' => 'invalid'];
        }

        // 2. Already expired
        if ($voucher['status'] === 'expired') {
            $db->commit();
            recordSecurityEvent('EXPIRED_VOUCHER', 'info', $code);
            return ['status' => 'expired'];
        }

        $now = time();
        $expiresAt = $voucher['expires_at'] ? strtotime($voucher['expires_at']) : null;

        // 3. Active but past expiry → mark expired
        if ($voucher['status'] === 'active' && $expiresAt !== null && $expiresAt <= $now) {
            $stmt = $db->prepare("UPDATE vouchers SET status = 'expired' WHERE id = :id");
            $stmt->execute([':id' => $voucher['id']]);
            closeVoucherSessions((int) $voucher['id'], 'expired');
            $db->commit();
            recordSecurityEvent('EXPIRED_VOUCHER', 'info', $code);
            return ['status' => 'expired'];
        }

        // 4. Unused → first use: set timers, open session, bind MAC as a hint
        if ($voucher['status'] === 'unused') {
            $firstUsedAt = date('Y-m-d H:i:s');
            $expiresAtTs = date('Y-m-d H:i:s', $now + $voucher['duration_seconds']);

            $stmt = $db->prepare("
                UPDATE vouchers
                SET status       = 'active',
                    first_used_at = :first_used_at,
                    expires_at    = :expires_at,
                    first_mac     = :first_mac
                WHERE id = :id
            ");
            $stmt->execute([
                ':first_used_at' => $firstUsedAt,
                ':expires_at'    => $expiresAtTs,
                ':first_mac'     => $clientMac,
                ':id'            => $voucher['id'],
            ]);

            applyVoucherRadiusPolicy($code, $voucher['plan_name'], $voucher['duration_seconds']);
            if ($clientMac) {
                lockVoucherMacInRadius($code, $clientMac);
            }
            $sessionId = upsertVoucherSession((int) $voucher['id'], $clientMac, $clientIp, $expiresAtTs);

            $db->commit();
            recordSecurityEvent('SESSION_STARTED', 'info', $code, $sessionId, ['mac' => $clientMac, 'ip' => $clientIp]);
            return ['status' => 'ok'];
        }

        // 5. Active, not expired → reconnect or reject other device
        if ($voucher['status'] === 'active' && $expiresAt !== null && $expiresAt > $now) {
            $policy = evaluateDevicePolicy($voucher, $clientMac);
            if (!$policy['ok']) {
                $db->commit();
                $eventType = ($policy['reason'] ?? '') === 'session_other_device' ? 'SESSION_LIMIT' : 'VOUCHER_REUSE';
                recordSecurityEvent($eventType, 'high', $code, null, [
                    'mac'        => $clientMac,
                    'bound_mac'  => $voucher['first_mac'],
                    'reason'     => $policy['reason'] ?? 'blocked',
                ]);
                return ['status' => 'in_use'];
            }

            $remaining = $expiresAt - $now;
            applyVoucherRadiusPolicy($code, $voucher['plan_name'], $remaining);

            if ($voucher['first_mac'] === null && $clientMac) {
                $stmt = $db->prepare("UPDATE vouchers SET first_mac = :mac WHERE id = :id");
                $stmt->execute([':mac' => $clientMac, ':id' => $voucher['id']]);
                lockVoucherMacInRadius($code, $clientMac);
            }

            $sessionId = upsertVoucherSession(
                (int) $voucher['id'],
                $clientMac,
                $clientIp,
                date('Y-m-d H:i:s', $expiresAt)
            );

            $db->commit();
            return ['status' => 'ok'];
        }

        // Fallback
        $db->commit();
        return ['status' => 'ok'];

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('prepareVoucherForAuth error: ' . $e->getMessage());
        return ['status' => 'invalid'];
    }
}

/**
 * Look up an active, non-expired voucher already bound to this device,
 * so it can be silently re-submitted without asking the user to retype it.
 */
function getActiveVoucherForMac(string $clientMac): ?string {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT v.code
        FROM voucher_sessions s
        JOIN vouchers v ON v.id = s.voucher_id
        WHERE s.client_mac = :mac
          AND s.status = 'active'
          AND v.status = 'active'
          AND v.expires_at > NOW()
        ORDER BY s.last_seen_at DESC
        LIMIT 1
    ");
    $stmt->execute([':mac' => $clientMac]);
    $code = $stmt->fetchColumn();
    if ($code) {
        return $code;
    }

    $stmt = $db->prepare("
        SELECT code FROM vouchers
        WHERE first_mac = :mac AND status = 'active' AND expires_at > NOW()
        ORDER BY expires_at DESC
        LIMIT 1
    ");
    $stmt->execute([':mac' => $clientMac]);
    $code = $stmt->fetchColumn();
    return $code ?: null;
}

/**
 * Admin action: unbind a voucher from its current device (lost/broken phone, etc.)
 * so the same code can be redeemed again on a new device without losing
 * remaining time.
 */
function releaseVoucherDevice(string $code): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM vouchers WHERE code = :code AND status = 'active'");
    $stmt->execute([':code' => $code]);
    $voucherId = $stmt->fetchColumn();
    if (!$voucherId) {
        return false;
    }

    closeVoucherSessions((int) $voucherId, 'admin_release');
    $stmt = $db->prepare("UPDATE vouchers SET first_mac = NULL WHERE id = :id");
    $stmt->execute([':id' => $voucherId]);
    unlockVoucherMacInRadius($code);
    recordSecurityEvent('DEVICE_RELEASED', 'info', $code);
    return true;
}

/**
 * Normalize a MAC address to uppercase AA:BB:CC:DD:EE:FF.
 */
function normalizeMacAddress(string $mac): string {
    $hex = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $mac));
    if (strlen($hex) !== 12) {
        return strtoupper($mac);
    }

    return implode(':', str_split($hex, 2));
}

/**
 * Build a POSIX extended-regex for FreeRADIUS Calling-Station-Id checks.
 * FreeRADIUS does not support PCRE flags like (?i); use per-character classes instead.
 */
function macToCallingStationRegex(string $mac): string {
    $hex = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $mac));
    if (strlen($hex) !== 12) {
        throw new InvalidArgumentException('Invalid MAC address');
    }

    $octets = [];
    for ($i = 0; $i < 12; $i += 2) {
        $a = $hex[$i];
        $b = $hex[$i + 1];
        $octets[] = '[' . $a . strtolower($a) . '][' . $b . strtolower($b) . ']';
    }

    return '^' . implode('[:-]', $octets) . '$';
}

/**
 * Bind a voucher's RADIUS auth to a single MAC via a Calling-Station-Id
 * check-item, so FreeRADIUS itself rejects a mismatched device even if
 * a request reaches it without going through the PHP portal page first.
 */
function lockVoucherMacInRadius(string $code, string $mac): void {
    $pattern = macToCallingStationRegex($mac);
    upsertRadAttribute('radcheck', $code, 'Calling-Station-Id', $pattern, '=~');
}

/**
 * Remove the RADIUS-side MAC lock so the voucher can rebind to a new device.
 */
function unlockVoucherMacInRadius(string $code): void {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM radcheck WHERE username = :username AND attribute = 'Calling-Station-Id'");
    $stmt->execute([':username' => $code]);
}

function upsertRadAttribute(string $table, string $username, string $attribute, string $value, string $op = ':='): void {
    $db = getDB();
    $allowed = ['radcheck', 'radreply'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Invalid RADIUS table');
    }

    $stmt = $db->prepare("SELECT id FROM {$table} WHERE username = :username AND attribute = :attribute");
    $stmt->execute([':username' => $username, ':attribute' => $attribute]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $db->prepare("UPDATE {$table} SET value = :value, op = :op WHERE id = :id");
        $stmt->execute([':value' => $value, ':op' => $op, ':id' => $existing['id']]);
        return;
    }

    $stmt = $db->prepare("INSERT INTO {$table} (username, attribute, op, value) VALUES (:username, :attribute, :op, :value)");
    $stmt->execute([
        ':username'  => $username,
        ':attribute' => $attribute,
        ':op'        => $op,
        ':value'     => $value,
    ]);
}

/**
 * Session-Timeout, Simultaneous-Use, interim-accounting interval, bandwidth
 * and data-quota policy written to FreeRADIUS tables for this voucher.
 *
 * Quota enforcement — two layers:
 *  1. FreeRADIUS sqlcounter (server-side, real-time): reads Max-All-Octets
 *     from radcheck and rejects Access-Requests once cumulative bytes >= limit.
 *     Requires the sqlcounter_quota module (see nginx/freeradius-sqlcounter-quota).
 *  2. PHP cron (bin/enforce_quota.php, every 1 min): polls radacct, expires
 *     the voucher in the DB and sends a CoA Disconnect to the AP.
 */
function applyVoucherRadiusPolicy(string $username, string $planName, int $timeoutSeconds): void {
    upsertRadAttribute('radreply', $username, 'Session-Timeout', (string) max(1, $timeoutSeconds));
    upsertRadAttribute('radcheck', $username, 'Simultaneous-Use', '1', ':=');

    // Tell the AP to send interim accounting packets every 60 s.
    // EAP650 valid range: 60–86400 s (values below 60 are ignored).
    // Without interim updates the AP only writes to radacct at session start/stop,
    // making server-side byte-counting unreliable mid-session.
    upsertRadAttribute('radreply', $username, 'Acct-Interim-Interval', '60');

    $pkg = getPackageByName($planName);
    if (!$pkg) {
        return;
    }

    $mbps = isset($pkg['bandwidth_mbps']) ? (int) $pkg['bandwidth_mbps'] : 0;
    if ($mbps > 0) {
        $bps = (string) ($mbps * 1000000);
        // WISPr bandwidth attributes — understood by most enterprise APs including TP-Link EAP.
        upsertRadAttribute('radreply', $username, 'WISPr-Bandwidth-Max-Down', $bps);
        upsertRadAttribute('radreply', $username, 'WISPr-Bandwidth-Max-Up',   $bps);
    }

    $quotaMb = isset($pkg['data_quota_mb']) ? (int) $pkg['data_quota_mb'] : 0;
    if ($quotaMb > 0) {
        $octets = (string) ($quotaMb * 1024 * 1024);
        // Max-All-Octets is the check attribute consumed by the FreeRADIUS
        // sqlcounter_quota module.  FreeRADIUS compares cumulative radacct
        // bytes against this value and rejects auth when exceeded.
        //
        // REMOVED: ChilliSpot-Max-Total-Octets — that is a CoovaChilli
        // vendor-specific attribute that TP-Link EAP650 silently ignores.
        upsertRadAttribute('radcheck', $username, 'Max-All-Octets', $octets, ':=');
    }
}

/**
 * Look up a voucher by code (for status page).
 */
function getVoucherByCode(string $code): ?array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM vouchers WHERE code = :code");
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Look up a voucher by id.
 */
function getVoucherById(int $id): ?array {
    if ($id <= 0) {
        return null;
    }
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM vouchers WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Unused voucher that has not been sold yet. Optionally scoped to a seller.
 */
function getUnsoldVoucherById(int $id, ?int $sellerId = null): ?array {
    if ($id <= 0) {
        return null;
    }
    $db = getDB();
    $sql = "
        SELECT v.id, v.code, v.plan_name, v.price, v.seller_id, v.status
        FROM vouchers v
        WHERE v.id = :id
          AND v.status = 'unused'
          AND NOT EXISTS (SELECT 1 FROM sales s WHERE s.voucher_code = v.code)
    ";
    $params = [':id' => $id];
    if ($sellerId !== null) {
        $sql .= " AND v.seller_id = :seller_id";
        $params[':seller_id'] = $sellerId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Mask an unsold voucher PIN, e.g. 4821937460 → 4821••••••
 */
function maskVoucherCode(string $code): string {
    $len = strlen($code);
    if ($len <= 4) {
        return str_repeat('•', max($len, 1));
    }
    return substr($code, 0, 4) . str_repeat('•', $len - 4);
}

/**
 * Whether a voucher row from getVouchers() has a matching sales record.
 */
function voucherIsSold(array $voucher): bool {
    $flag = $voucher['is_sold'] ?? 0;
    return $flag === true || $flag === 1 || $flag === '1' || $flag === 't';
}

/**
 * Render a voucher code for admin/seller UI.
 * Unsold codes are masked with no copy control. Sold codes show in full with Copy.
 *
 * @param string $size 'inline' for tables, 'reveal' for the post-sale confirmation
 */
function renderVoucherCode(string $code, bool $revealed, string $size = 'inline'): string {
    if (!$revealed) {
        return '<span class="code-cell code-masked">' . htmlspecialchars(maskVoucherCode($code), ENT_QUOTES, 'UTF-8') . '</span>';
    }
    $safe = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $btn = '<button type="button" class="copy-btn" data-copy="' . $safe . '" aria-label="Copy voucher code">Copy</button>';
    if ($size === 'reveal') {
        return '<div class="voucher-code-reveal"><span class="voucher-code-full">' . $safe . '</span>' . $btn . '</div>';
    }
    return '<span class="voucher-code-wrap"><span class="code-cell">' . $safe . '</span>' . $btn . '</span>';
}

// ─── Admin functions below (unchanged) ────────────────────────────

/**
 * Generate vouchers as unsold stock. Generating a voucher is NOT a sale —
 * a sale is only recorded when someone explicitly records it on the Sales page.
 *
 * @param string    $packageName   Package name (display name)
 * @param int       $durationSec   Duration in seconds
 * @param float     $price         Price in TZS
 * @param int       $quantity      Number of vouchers to generate
 * @param string    $createdBy     Username of the creator
 * @param int|null  $sellerId      Seller user ID (null for admin-only generation)
 * @return array                   Array of generated voucher codes
 */
function generateVouchers(string $packageName, int $durationSec, float $price, int $quantity, string $createdBy, ?int $sellerId = null): array {
    if (empty($packageName) || $durationSec < 60) {
        throw new Exception('Invalid package data.');
    }
    if ($quantity < 1 || $quantity > 100) {
        throw new Exception('Quantity must be 1-100.');
    }

    // Normalize seller_id: 0 or invalid becomes null
    if ($sellerId !== null && $sellerId <= 0) {
        $sellerId = null;
    }

    // Verify seller exists in DB if provided
    if ($sellerId !== null) {
        $db = getDB();
        $check = $db->prepare("SELECT id FROM users WHERE id = :id AND is_deleted = false");
        $check->execute([':id' => $sellerId]);
        if (!$check->fetch()) {
            $sellerId = null; // Seller not found, treat as admin generation
        }
    }

    $db = getDB();
    $generated = [];

    try {
        $db->beginTransaction();

        for ($i = 0; $i < $quantity; $i++) {
            // Generate unique code
            do {
                $code = generateVoucherCode(10);
                $stmt = $db->prepare("SELECT COUNT(*) FROM vouchers WHERE code = :code");
                $stmt->execute([':code' => $code]);
            } while ($stmt->fetchColumn() > 0);

            // 1. Create voucher
            $stmt = $db->prepare("
                INSERT INTO vouchers (code, plan_name, duration_seconds, price, status, created_by, seller_id)
                VALUES (:code, :plan_name, :duration_seconds, :price, 'unused', :created_by, :seller_id)
            ");
            $stmt->execute([
                ':code'            => $code,
                ':plan_name'       => $packageName,
                ':duration_seconds'=> $durationSec,
                ':price'           => $price,
                ':created_by'      => $createdBy,
                ':seller_id'       => $sellerId,
            ]);

            // 2. radcheck: username=password=code (Cleartext-Password for PAP)
            $stmt = $db->prepare("
                INSERT INTO radcheck (username, attribute, op, value)
                VALUES (:username, 'Cleartext-Password', ':=', :password)
            ");
            $stmt->execute([':username' => $code, ':password' => $code]);

            applyVoucherRadiusPolicy($code, $packageName, $durationSec);

            $generated[] = $code;
        }

        $db->commit();
        return $generated;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Get all vouchers with optional filters. Expired vouchers are always
 * excluded from the results — they are never shown in admin voucher lists.
 *
 * @param string|null $status    Filter by status (unused|active)
 * @param string|null $search    Search by code
 * @param int|null    $sellerId  Filter by seller who generated
 * @param string|null $planName  Filter by package/plan name
 * @param int         $limit
 * @param int         $offset
 * @return array
 */
function getVouchers(?string $status = null, ?string $search = null, ?int $sellerId = null, ?string $planName = null, int $limit = 100, int $offset = 0): array {
    $db = getDB();

    $sql = "SELECT v.id, v.code, v.plan_name, v.duration_seconds, v.price, v.status,
                   v.created_at, v.first_used_at, v.expires_at, v.created_by, v.seller_id, v.first_mac,
                   CASE WHEN EXISTS (SELECT 1 FROM sales s WHERE s.voucher_code = v.code) THEN 1 ELSE 0 END AS is_sold
            FROM vouchers v
            WHERE v.status != 'expired'";
    $params = [];

    if ($status) {
        $sql .= " AND v.status = :status";
        $params[':status'] = $status;
    }
    if ($search) {
        $sql .= " AND v.code LIKE :search";
        $params[':search'] = '%' . $search . '%';
    }
    if ($sellerId !== null) {
        $sql .= " AND v.seller_id = :seller_id";
        $params[':seller_id'] = $sellerId;
    }
    if ($planName) {
        $sql .= " AND v.plan_name = :plan_name";
        $params[':plan_name'] = $planName;
    }

    $sql .= " ORDER BY v.created_at DESC LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * Count vouchers by status.
 */
function countVouchersByStatus(): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT
            COUNT(*)                                    AS total,
            COUNT(CASE WHEN status = 'unused'   THEN 1 END) AS unused,
            COUNT(CASE WHEN status = 'active'   THEN 1 END) AS active,
            COUNT(CASE WHEN status = 'expired'  THEN 1 END) AS expired
        FROM vouchers
    ");
    $stmt->execute();
    return $stmt->fetch();
}

/**
 * Force expire a voucher (admin function).
 */
function forceExpireVoucher(string $code): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM vouchers WHERE code = :code AND status != 'expired'");
    $stmt->execute([':code' => $code]);
    $voucherId = $stmt->fetchColumn();
    if (!$voucherId) {
        return false;
    }

    $stmt = $db->prepare("
        UPDATE vouchers
        SET status = 'expired', expires_at = COALESCE(expires_at, CURRENT_TIMESTAMP)
        WHERE id = :id
    ");
    $stmt->execute([':id' => $voucherId]);
    closeVoucherSessions((int) $voucherId, 'admin_expire', 'blocked');
    recordSecurityEvent('EXPIRED_VOUCHER', 'medium', $code, null, ['source' => 'admin']);
    radius_disconnect($code);
    return true;
}

/**
 * Sweep the database for active vouchers whose expires_at has passed and
 * mark them 'expired'.
 *
 * WHY THIS IS NEEDED
 * ------------------
 * FreeRADIUS enforces time via Session-Timeout in radreply — it stops
 * accepting auth for the voucher once the session timer runs out.
 * However, the *database* status column is only updated when:
 *   (a) the customer enters the code at the portal (prepareVoucherForAuth)
 *   (b) an admin manually force-expires it
 *   (c) sharing enforcement fires
 * Without this sweep, a voucher whose time ran out stays 'active' in the DB
 * indefinitely, causing confusing displays in the admin panel and status page.
 *
 * Run via bin/expire_vouchers.php cron (every minute).
 *
 * @return array{expired: int, errors: int}
 */
function expireOverdueVouchers(): array {
    $db = getDB();

    // Fetch all active vouchers past their expires_at
    $stmt = $db->query("
        SELECT id, code
        FROM   vouchers
        WHERE  status     = 'active'
          AND  expires_at < NOW()
        ORDER  BY expires_at
    ");

    $expired = 0;
    $errors  = 0;

    while ($row = $stmt->fetch()) {
        try {
            // Mark expired
            $db->prepare("
                UPDATE vouchers
                SET status = 'expired'
                WHERE id = :id AND status = 'active'
            ")->execute([':id' => (int) $row['id']]);

            // Close any PHP-tracked sessions
            closeVoucherSessions((int) $row['id'], 'time_expired', 'closed');

            // Log event
            recordSecurityEvent('EXPIRED_VOUCHER', 'low', $row['code'], null, [
                'source' => 'expiry_cron',
            ]);

            error_log('[expiry] Voucher ' . $row['code'] . ' marked expired (time elapsed)');
            $expired++;
        } catch (Exception $e) {
            $errors++;
            error_log('[expiry] Error expiring voucher ' . $row['code'] . ': ' . $e->getMessage());
        }
    }

    return ['expired' => $expired, 'errors' => $errors];
}

/**
 * Admin disconnect: expire active vouchers or clear live rows when already expired.
 * @return array{ok: bool, message: string}
 */
function adminDisconnectSession(string $code): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, status FROM vouchers WHERE code = :code");
    $stmt->execute([':code' => $code]);
    $voucher = $stmt->fetch();
    if (!$voucher) {
        return ['ok' => false, 'message' => "Unknown voucher $code."];
    }

    $session = getTrackedSessionForVoucher((int) $voucher['id']);
    $mac = $session['client_mac'] ?? null;

    if ($voucher['status'] !== 'expired') {
        if (!forceExpireVoucher($code)) {
            return ['ok' => false, 'message' => "Could not expire voucher $code."];
        }
        return ['ok' => true, 'message' => "Voucher expired and disconnect sent for $code."];
    }

    closeVoucherSessions((int) $voucher['id'], 'admin_disconnect', 'blocked');
    $disconnect = radius_disconnect($code);

    if ($disconnect['success']) {
        return ['ok' => true, 'message' => "Disconnect sent for $code."];
    }

    $hint = $mac
        ? " Kick $mac from the AP client list."
        : ' Kick the device from the AP client list.';
    return [
        'ok'      => true,
        'message' => "Session cleared for $code. Wi‑Fi may stay connected (AP does not accept CoA).$hint",
    ];
}
