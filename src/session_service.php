<?php
/**
 * Voucher sessions + security events.
 * Session is the primary authorization object; MAC/IP/acct id are hints.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/radius_client.php';

function recordSecurityEvent(
    string $eventType,
    string $severity = 'info',
    ?string $voucherCode = null,
    ?int $sessionId = null,
    ?array $metadata = null
): void {
    $db = getDB();
    try {
        $stmt = $db->prepare("
            INSERT INTO security_events (session_id, voucher_code, event_type, severity, metadata)
            VALUES (:session_id, :voucher_code, :event_type, :severity, :metadata::jsonb)
        ");
        $stmt->execute([
            ':session_id'   => $sessionId,
            ':voucher_code' => $voucherCode,
            ':event_type'   => $eventType,
            ':severity'     => $severity,
            ':metadata'     => $metadata ? json_encode($metadata) : null,
        ]);
    } catch (Exception $e) {
        error_log('recordSecurityEvent failed: ' . $e->getMessage());
    }
}

function closeVoucherSessions(int $voucherId, string $reason, string $status = 'closed'): void {
    $db = getDB();
    $stmt = $db->prepare("
        UPDATE voucher_sessions
        SET status = :status, closed_at = NOW(), close_reason = :reason, last_seen_at = NOW()
        WHERE voucher_id = :voucher_id AND status = 'active'
    ");
    $stmt->execute([
        ':status'     => $status,
        ':reason'     => $reason,
        ':voucher_id' => $voucherId,
    ]);
}

function getActiveSessionForVoucher(int $voucherId): ?array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT * FROM voucher_sessions
        WHERE voucher_id = :voucher_id AND status = 'active'
        ORDER BY last_seen_at DESC
        LIMIT 1
    ");
    $stmt->execute([':voucher_id' => $voucherId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function upsertVoucherSession(
    int $voucherId,
    ?string $clientMac,
    ?string $clientIp,
    ?string $expiresAt
): int {
    $db = getDB();
    $existing = getActiveSessionForVoucher($voucherId);

    if ($existing) {
        $stmt = $db->prepare("
            UPDATE voucher_sessions
            SET last_seen_at = NOW(),
                client_mac = COALESCE(:mac, client_mac),
                client_ip = COALESCE(:ip, client_ip),
                expires_at = COALESCE(:expires_at, expires_at)
            WHERE id = :id
        ");
        $stmt->execute([
            ':mac'        => $clientMac,
            ':ip'         => $clientIp,
            ':expires_at' => $expiresAt,
            ':id'         => $existing['id'],
        ]);
        return (int) $existing['id'];
    }

    $stmt = $db->prepare("
        INSERT INTO voucher_sessions (voucher_id, client_mac, client_ip, expires_at, status)
        VALUES (:voucher_id, :mac, :ip, :expires_at, 'active')
        RETURNING id
    ");
    $stmt->execute([
        ':voucher_id' => $voucherId,
        ':mac'        => $clientMac,
        ':ip'         => $clientIp,
        ':expires_at' => $expiresAt,
    ]);
    return (int) $stmt->fetchColumn();
}

/**
 * Whether this device may use the voucher. Session + MAC are hints together.
 * @return array{ok: bool, reason?: string}
 */
function evaluateDevicePolicy(array $voucher, ?string $clientMac): array {
    $session = getActiveSessionForVoucher((int) $voucher['id']);
    $boundMac = $voucher['first_mac'] ?? null;

    if ($session && $session['client_mac'] && $clientMac && strcasecmp($session['client_mac'], $clientMac) !== 0) {
        return ['ok' => false, 'reason' => 'session_other_device'];
    }

    if ($boundMac && $clientMac && strcasecmp($boundMac, $clientMac) !== 0) {
        return ['ok' => false, 'reason' => 'mac_lock'];
    }

    return ['ok' => true];
}

function getAdminActiveSessions(int $limit = 100): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT s.*, v.code, v.plan_name, v.status AS voucher_status, v.expires_at AS voucher_expires_at
        FROM voucher_sessions s
        JOIN vouchers v ON v.id = s.voucher_id
        WHERE s.status = 'active'
        ORDER BY s.last_seen_at DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function getSecurityEvents(int $limit = 100, ?string $eventType = null): array {
    $db = getDB();
    $sql = "SELECT * FROM security_events WHERE 1=1";
    $params = [];
    if ($eventType) {
        $sql .= " AND event_type = :type";
        $params[':type'] = $eventType;
    }
    $sql .= " ORDER BY created_at DESC LIMIT :limit";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Pull live RADIUS accounting into voucher_sessions (best-effort).
 */
function syncSessionsFromRadacct(): int {
    $db = getDB();
    try {
        $acct = $db->query("
            SELECT username, acctsessionid, framedipaddress, callingstationid,
                   nasipaddress, acctstarttime, acctupdatetime
            FROM radacct
            WHERE acctstoptime IS NULL
        ");
    } catch (Exception $e) {
        error_log('radacct not available: ' . $e->getMessage());
        return 0;
    }

    $updated = 0;
    while ($row = $acct->fetch()) {
        $code = $row['username'] ?? '';
        if ($code === '') {
            continue;
        }
        $vStmt = $db->prepare("SELECT id FROM vouchers WHERE code = :code");
        $vStmt->execute([':code' => $code]);
        $voucherId = $vStmt->fetchColumn();
        if (!$voucherId) {
            continue;
        }

        $session = getActiveSessionForVoucher((int) $voucherId);
        $mac = $row['callingstationid'] ?: null;
        $ip = $row['framedipaddress'] ?: null;
        $acctId = $row['acctsessionid'] ?: null;
        $nas = $row['nasipaddress'] ?: null;

        if ($session) {
            $stmt = $db->prepare("
                UPDATE voucher_sessions
                SET last_seen_at = NOW(),
                    client_mac = COALESCE(:mac, client_mac),
                    client_ip = COALESCE(:ip, client_ip),
                    gateway_session_id = COALESCE(:acct, gateway_session_id),
                    nas_ip = COALESCE(:nas, nas_ip)
                WHERE id = :id
            ");
            $stmt->execute([
                ':mac'  => $mac,
                ':ip'   => $ip,
                ':acct' => $acctId,
                ':nas'  => $nas,
                ':id'   => $session['id'],
            ]);
            $updated++;
        } else {
            $stmt = $db->prepare("
                INSERT INTO voucher_sessions
                    (voucher_id, client_mac, client_ip, gateway_session_id, nas_ip, status)
                VALUES (:voucher_id, :mac, :ip, :acct, :nas, 'active')
            ");
            $stmt->execute([
                ':voucher_id' => $voucherId,
                ':mac'        => $mac,
                ':ip'         => $ip,
                ':acct'       => $acctId,
                ':nas'        => $nas,
            ]);
            $updated++;
        }
    }

    detectSharingFromRadacct();
    return $updated;
}

/**
 * Best-effort: multiple live radacct rows or MACs for one voucher.
 */
function detectSharingFromRadacct(): void {
    $db = getDB();
    try {
        $stmt = $db->query("
            SELECT username,
                   COUNT(*) AS live_sessions,
                   COUNT(DISTINCT NULLIF(callingstationid, '')) AS distinct_macs
            FROM radacct
            WHERE acctstoptime IS NULL
            GROUP BY username
            HAVING COUNT(*) > 1 OR COUNT(DISTINCT NULLIF(callingstationid, '')) > 1
        ");
    } catch (Exception $e) {
        return;
    }

    while ($row = $stmt->fetch()) {
        $code = $row['username'];
        $type = ((int) $row['distinct_macs'] > 1) ? 'MULTIPLE_DEVICE' : 'SESSION_LIMIT';
        $recent = $db->prepare("
            SELECT id FROM security_events
            WHERE voucher_code = :code AND event_type = :type
              AND created_at > NOW() - INTERVAL '15 minutes'
            LIMIT 1
        ");
        $recent->execute([':code' => $code, ':type' => $type]);
        if ($recent->fetch()) {
            continue;
        }

        // Kick the extra session. radius_disconnect() targets the most
        // recently started radacct session for this username, i.e. the
        // intruding device rather than the originally bound one.
        $disconnect = radius_disconnect($code);

        recordSecurityEvent($type, 'high', $code, null, [
            'live_sessions' => (int) $row['live_sessions'],
            'distinct_macs' => (int) $row['distinct_macs'],
            'source'        => 'radacct',
            'action'        => $disconnect['success'] ? 'disconnected' : 'coa_failed',
            'action_detail' => $disconnect['message'] ?? null,
        ]);

        // Repeated violations for the same voucher within the last hour ->
        // suspend it outright instead of only kicking the extra session.
        $repeat = $db->prepare("
            SELECT COUNT(*) FROM security_events
            WHERE voucher_code = :code AND event_type = :type
              AND created_at > NOW() - INTERVAL '1 hour'
        ");
        $repeat->execute([':code' => $code, ':type' => $type]);
        if ((int) $repeat->fetchColumn() >= 2) {
            suspendVoucherForSharing($code);
        }
    }
}

/**
 * Suspend a voucher after repeated sharing detections: stop it from
 * authenticating again and disconnect any live session.
 */
function suspendVoucherForSharing(string $code): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, status FROM vouchers WHERE code = :code");
    $stmt->execute([':code' => $code]);
    $voucher = $stmt->fetch();
    if (!$voucher || $voucher['status'] === 'expired') {
        return;
    }

    $db->prepare("UPDATE vouchers SET status = 'expired' WHERE id = :id")
        ->execute([':id' => $voucher['id']]);
    closeVoucherSessions((int) $voucher['id'], 'auto_suspend_sharing', 'blocked');

    // Force FreeRADIUS to reject future auth attempts for this code,
    // independent of the still-valid Cleartext-Password row.
    $stmt = $db->prepare("SELECT id FROM radcheck WHERE username = :u AND attribute = 'Auth-Type'");
    $stmt->execute([':u' => $code]);
    if ($stmt->fetch()) {
        $db->prepare("UPDATE radcheck SET value = 'Reject', op = ':=' WHERE username = :u AND attribute = 'Auth-Type'")
            ->execute([':u' => $code]);
    } else {
        $db->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (:u, 'Auth-Type', ':=', 'Reject')")
            ->execute([':u' => $code]);
    }

    radius_disconnect($code);
    recordSecurityEvent('VOUCHER_SUSPENDED', 'high', $code, null, ['reason' => 'repeated_sharing_detected']);
}

function countOpenSecurityEvents(): int {
    $db = getDB();
    return (int) $db->query("SELECT COUNT(*) FROM security_events WHERE resolved_at IS NULL")->fetchColumn();
}
