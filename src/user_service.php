<?php
/**
 * User Service — Seller CRUD operations and user management
 * v2: Seller role support
 */

require_once __DIR__ . '/db.php';

// ── Seller Creation ─────────────────────────────────────────────

/**
 * Create a new seller account
 *
 * @param string      $username   Unique username
 * @param string      $password   Plain-text password (will be hashed)
 * @param string|null $fullName   Seller's full name
 * @param string|null $phone      Optional phone number
 * @param int         $createdBy  Admin user ID performing the creation
 * @return int                    New seller's user ID
 * @throws Exception              On validation or DB errors
 */
function createSeller(string $username, string $password, ?string $fullName, ?string $phone, ?int $createdBy = null): int {
    // Validate username
    $username = trim($username);
    if (empty($username) || strlen($username) < 3 || strlen($username) > 64) {
        throw new Exception('Username must be 3-64 characters.');
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        throw new Exception('Username can only contain letters, numbers, and _.');
    }

    // Validate password
    if (strlen($password) < 8) {
        throw new Exception('Password must be at least 8 characters.');
    }

    // Validate phone if provided
    if (!empty($phone)) {
        $phone = validateAndFormatPhone($phone);
    } else {
        $phone = null;
    }

    $db = getDB();

    // Check uniqueness
    $stmt = $db->prepare("SELECT id FROM users WHERE username = :u");
    $stmt->execute([':u' => $username]);
    if ($stmt->fetch()) {
        throw new Exception('This username is already taken.');
    }

    if ($phone) {
        $stmt = $db->prepare("SELECT id FROM users WHERE phone = :p");
        $stmt->execute([':p' => $phone]);
        if ($stmt->fetch()) {
            throw new Exception('This phone number is already in use.');
        }
    }

    // Hash password
    $hash = password_hash($password, PASSWORD_ARGON2ID);

    // Insert
    $stmt = $db->prepare("
        INSERT INTO users (role, username, full_name, phone, password_hash, is_active, created_by)
        VALUES ('seller', :username, :full_name, :phone, :password_hash, 1, :created_by)
    ");
    $stmt->execute([
        ':username'      => $username,
        ':full_name'     => $fullName ?: null,
        ':phone'         => $phone,
        ':password_hash' => $hash,
        ':created_by'    => $createdBy ?: null,
    ]);

    $newId = (int) $db->lastInsertId();

    writeAuditLog('seller_created', $createdBy ?: null, 'seller', (string) $newId, [
        'username'  => $username,
        'full_name' => $fullName,
    ]);

    return $newId;
}

// ── Seller Retrieval ────────────────────────────────────────────

/**
 * Get a single seller by ID
 */
function getSellerById(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT u.*,
               creator.username AS created_by_username
        FROM users u
        LEFT JOIN users creator ON u.created_by = creator.id
        WHERE u.id = :id AND u.role = 'seller'
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Get a seller by username
 */
function getSellerByUsername(string $username): ?array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT * FROM users WHERE username = :u AND role = 'seller'
    ");
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Get all sellers with optional search and status filter
 *
 * @param string|null $search  Search by username or full_name
 * @param bool|null   $active  Filter by active status (null = all)
 * @param int         $limit
 * @param int         $offset
 * @return array
 */
function getSellers(?string $search = null, ?bool $active = null, int $limit = 50, int $offset = 0, bool $includeDeleted = false): array {
    $db = getDB();

    $sql = "
        SELECT u.*,
               creator.username AS created_by_username,
               (SELECT COUNT(*) FROM vouchers v WHERE v.seller_id = u.id) AS total_vouchers_generated,
               (SELECT COUNT(*) FROM sales s WHERE s.seller_id = u.id) AS total_sales
        FROM users u
        LEFT JOIN users creator ON u.created_by = creator.id
        WHERE u.role = 'seller'
    ";

    if (!$includeDeleted) {
        $sql .= " AND u.is_deleted = false";
    }
    $params = [];

    if ($search) {
        $sql .= " AND (u.username LIKE :search OR u.full_name LIKE :search OR u.phone LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    if ($active !== null) {
        $sql .= " AND u.is_active = :active";
        $params[':active'] = $active ? 1 : 0;
    }

    $sql .= " ORDER BY u.created_at DESC LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * Count sellers with optional filters
 */
function countSellers(?string $search = null, ?bool $active = null, bool $includeDeleted = false): int {
    $db = getDB();

    $sql = "SELECT COUNT(*) FROM users WHERE role = 'seller'";

    if (!$includeDeleted) {
        $sql .= " AND is_deleted = false";
    }
    $params = [];

    if ($search) {
        $sql .= " AND (username LIKE :search OR full_name LIKE :search OR phone LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    if ($active !== null) {
        $sql .= " AND is_active = :active";
        $params[':active'] = $active ? 1 : 0;
    }

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    return (int) $stmt->fetchColumn();
}

// ── Seller Status Management ────────────────────────────────────

/**
 * Activate a seller account
 */
function activateSeller(int $sellerId, ?int $adminUserId = null): bool {
    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET is_active = true, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND role = 'seller'");
    $stmt->execute([':id' => $sellerId]);

    if ($stmt->rowCount() > 0) {
        writeAuditLog('seller_activated', $adminUserId, 'seller', (string) $sellerId);
        return true;
    }
    return false;
}

/**
 * Deactivate a seller account
 */
function deactivateSeller(int $sellerId, ?int $adminUserId = null): bool {
    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET is_active = false, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND role = 'seller'");
    $stmt->execute([':id' => $sellerId]);

    if ($stmt->rowCount() > 0) {
        writeAuditLog('seller_deactivated', $adminUserId, 'seller', (string) $sellerId);
        return true;
    }
    return false;
}

/**
 * Soft-delete a seller account (marks as deleted, no data is lost)
 */
function deleteSeller(int $sellerId, ?int $adminUserId = null): bool {
    $db = getDB();

    $stmt = $db->prepare("
        UPDATE users
        SET is_active = false, is_deleted = true, updated_at = CURRENT_TIMESTAMP
        WHERE id = :id AND role = 'seller'
    ");
    $stmt->execute([':id' => $sellerId]);

    if ($stmt->rowCount() > 0) {
        writeAuditLog('seller_soft_deleted', $adminUserId, 'seller', (string) $sellerId);
        return true;
    }
    return false;
}

/**
 * Restore a soft-deleted seller
 */
function restoreSeller(int $sellerId, ?int $adminUserId = null): bool {
    $db = getDB();
    $stmt = $db->prepare("
        UPDATE users
        SET is_deleted = false, updated_at = CURRENT_TIMESTAMP
        WHERE id = :id AND role = 'seller' AND is_deleted = true
    ");
    $stmt->execute([':id' => $sellerId]);

    if ($stmt->rowCount() > 0) {
        writeAuditLog('seller_restored', $adminUserId, 'seller', (string) $sellerId);
        return true;
    }
    return false;
}

/**
 * Update seller profile (name, phone)
 */
function updateSeller(int $sellerId, ?string $fullName, ?string $phone, ?int $adminUserId = null): bool {
    $db = getDB();

    $sets = [];
    $params = [':id' => $sellerId];

    if ($fullName !== null) {
        $sets[] = "full_name = :full_name";
        $params[':full_name'] = $fullName;
    }

    if ($phone !== null) {
        if (!empty($phone)) {
            $phone = validateAndFormatPhone($phone);
        } else {
            $phone = null;
        }
        // Check uniqueness
        if ($phone) {
            $stmt = $db->prepare("SELECT id FROM users WHERE phone = :p AND id != :id");
            $stmt->execute([':p' => $phone, ':id' => $sellerId]);
            if ($stmt->fetch()) {
                throw new Exception('This phone number is already in use.');
            }
        }
        $sets[] = "phone = :phone";
        $params[':phone'] = $phone;
    }

    if (empty($sets)) {
        return false;
    }

    $sets[] = "updated_at = CURRENT_TIMESTAMP";
    $sql = "UPDATE users SET " . implode(', ', $sets) . " WHERE id = :id AND role = 'seller'";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() > 0) {
        writeAuditLog('seller_updated', $adminUserId, 'seller', (string) $sellerId, [
            'full_name' => $fullName,
            'phone'     => $phone,
        ]);
        return true;
    }
    return false;
}

/**
 * Change seller password (admin function)
 */
function changeSellerPassword(int $sellerId, string $newPassword, ?int $adminUserId = null): bool {
    if (strlen($newPassword) < 8) {
        throw new Exception('Password must be at least 8 characters.');
    }

    $db = getDB();
    $hash = password_hash($newPassword, PASSWORD_ARGON2ID);

    $stmt = $db->prepare("UPDATE users SET password_hash = :hash, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND role = 'seller'");
    $stmt->execute([':hash' => $hash, ':id' => $sellerId]);

    if ($stmt->rowCount() > 0) {
        writeAuditLog('seller_password_changed', $adminUserId, 'seller', (string) $sellerId);
        return true;
    }
    return false;
}

// ── Phone Validation ────────────────────────────────────────────

/**
 * Validate and format a Tanzanian phone number
 * Accepts: 0XXXXXXXXX, +255XXXXXXXXX, 255XXXXXXXXX
 * Returns: +255XXXXXXXXX format
 *
 * @throws Exception if invalid
 */
function validateAndFormatPhone(string $phone): string {
    $phone = preg_replace('/[\s\-\(\)]/', '', $phone); // Strip formatting

    if (preg_match('/^\+255\d{9}$/', $phone)) {
        return $phone;
    }
    if (preg_match('/^255\d{9}$/', $phone)) {
        return '+' . $phone;
    }
    if (preg_match('/^0\d{9}$/', $phone)) {
        return '+255' . substr($phone, 1);
    }

    throw new Exception('Invalid phone number. Use format: 0712345678 or +255712345678');
}

// ── Seller Stats ────────────────────────────────────────────────

/**
 * Get summary stats for all sellers (admin view)
 */
function getSellerSummaryStats(): array {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS total_sellers,
            COUNT(CASE WHEN is_active = true THEN 1 END) AS active_sellers,
            COUNT(CASE WHEN is_active = false THEN 1 END) AS inactive_sellers
        FROM users
        WHERE role = 'seller'
    ");
    $stmt->execute();
    return $stmt->fetch();
}
