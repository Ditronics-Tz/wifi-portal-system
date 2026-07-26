<?php
/**
 * Voucher service — DB-only timer logic
 * AP handles RADIUS auth directly; PHP only prepares/updates DB state.
 */

require_once '/var/www/voucher-portal/src/db.php';

/**
 * Prepare voucher for AP authentication (DB-only, no RADIUS call).
 * Called BEFORE the auto-submit form sends credentials to AP.
 *
 * @param string $code Voucher code
 * @return array ['status' => 'ok'|'invalid'|'expired']
 */
function prepareVoucherForAuth(string $code): array {
    // Validate code format
    if (!preg_match(VOUCHER_CODE_PATTERN, $code)) {
        return ['status' => 'invalid'];
    }

    $db = getDB();

    try {
        $db->beginTransaction();

        // Lock row to prevent race conditions
        $stmt = $db->prepare("
            SELECT id, code, plan_name, duration_seconds, status, first_used_at, expires_at
            FROM vouchers
            WHERE code = :code
            FOR UPDATE
        ");
        $stmt->execute([':code' => $code]);
        $voucher = $stmt->fetch();

        // 1. Not found
        if (!$voucher) {
            $db->rollBack();
            return ['status' => 'invalid'];
        }

        // 2. Already expired
        if ($voucher['status'] === 'expired') {
            $db->commit();
            return ['status' => 'expired'];
        }

        $now = time();
        $expiresAt = $voucher['expires_at'] ? strtotime($voucher['expires_at']) : null;

        // 3. Active but past expiry → mark expired
        if ($voucher['status'] === 'active' && $expiresAt !== null && $expiresAt <= $now) {
            $stmt = $db->prepare("UPDATE vouchers SET status = 'expired' WHERE id = :id");
            $stmt->execute([':id' => $voucher['id']]);
            $db->commit();
            return ['status' => 'expired'];
        }

        // 4. Unused → first use: set timers
        if ($voucher['status'] === 'unused') {
            $firstUsedAt = date('Y-m-d H:i:s');
            $expiresAt   = date('Y-m-d H:i:s', $now + $voucher['duration_seconds']);

            $stmt = $db->prepare("
                UPDATE vouchers
                SET status       = 'active',
                    first_used_at = :first_used_at,
                    expires_at    = :expires_at
                WHERE id = :id
            ");
            $stmt->execute([
                ':first_used_at' => $firstUsedAt,
                ':expires_at'    => $expiresAt,
                ':id'            => $voucher['id'],
            ]);

            // Set full duration in radreply
            updateRadreplySessionTimeout($code, $voucher['duration_seconds']);

            $db->commit();
            return ['status' => 'ok'];
        }

        // 5. Active, not expired → reconnect mid-voucher
        if ($voucher['status'] === 'active' && $expiresAt !== null && $expiresAt > $now) {
            $remaining = $expiresAt - $now;

            // Update radreply with remaining time
            updateRadreplySessionTimeout($code, $remaining);

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
 * Update radreply Session-Timeout for a user (upsert).
 */
function updateRadreplySessionTimeout(string $username, int $timeout): void {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT id FROM radreply
        WHERE username = :username AND attribute = 'Session-Timeout'
    ");
    $stmt->execute([':username' => $username]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $db->prepare("
            UPDATE radreply
            SET value = :value
            WHERE username = :username AND attribute = 'Session-Timeout'
        ");
    } else {
        $stmt = $db->prepare("
            INSERT INTO radreply (username, attribute, op, value)
            VALUES (:username, 'Session-Timeout', ':=', :value)
        ");
    }

    $stmt->execute([
        ':username' => $username,
        ':value'    => (string) $timeout,
    ]);
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

// ─── Admin functions below (unchanged) ────────────────────────────

/**
 * Generate vouchers (admin function).
 */
function generateVouchers(string $planKey, int $quantity, string $createdBy): array {
    if (!isset(PLANS[$planKey])) {
        throw new Exception('Invalid plan');
    }
    if ($quantity < 1 || $quantity > 100) {
        throw new Exception('Invalid quantity');
    }

    $plan = PLANS[$planKey];
    $db   = getDB();
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

            $stmt = $db->prepare("
                INSERT INTO vouchers (code, plan_name, duration_seconds, price, status, created_by)
                VALUES (:code, :plan_name, :duration_seconds, :price, 'unused', :created_by)
            ");
            $stmt->execute([
                ':code'            => $code,
                ':plan_name'       => $plan['name'],
                ':duration_seconds'=> $plan['duration_seconds'],
                ':price'           => $plan['price'],
                ':created_by'      => $createdBy,
            ]);

            // radcheck: username=password=code (Cleartext-Password for PAP)
            $stmt = $db->prepare("
                INSERT INTO radcheck (username, attribute, op, value)
                VALUES (:username, 'Cleartext-Password', ':=', :password)
            ");
            $stmt->execute([':username' => $code, ':password' => $code]);

            // radreply: Session-Timeout placeholder
            $stmt = $db->prepare("
                INSERT INTO radreply (username, attribute, op, value)
                VALUES (:username, 'Session-Timeout', ':=', :timeout)
            ");
            $stmt->execute([':username' => $code, ':timeout' => (string) $plan['duration_seconds']]);

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
 * Get all vouchers with optional filters.
 */
function getVouchers(?string $status = null, ?string $search = null, int $limit = 100, int $offset = 0): array {
    $db = getDB();

    $sql = "SELECT id, code, plan_name, duration_seconds, price, status,
                   created_at, first_used_at, expires_at, created_by
            FROM vouchers WHERE 1=1";
    $params = [];

    if ($status) {
        $sql .= " AND status = :status";
        $params[':status'] = $status;
    }
    if ($search) {
        $sql .= " AND code ILIKE :search";
        $params[':search'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

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
    $stmt = $db->prepare("
        UPDATE vouchers
        SET status = 'expired', expires_at = COALESCE(expires_at, NOW())
        WHERE code = :code AND status != 'expired'
    ");
    $stmt->execute([':code' => $code]);
    return $stmt->rowCount() > 0;
}
