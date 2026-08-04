<?php
/**
 * Package Service — Admin-managed WiFi packages
 * Sellers can only view active packages; Admin has full CRUD
 */

require_once __DIR__ . '/db.php';

// ── Admin CRUD ──────────────────────────────────────────────────

/**
 * Create a new package
 */
function createPackage(string $name, string $slug, int $durationSeconds, float $price, ?int $bandwidthMbps, ?int $dataQuotaMb, ?string $description, ?int $createdBy = null): int {
    $name = trim($name);
    $slug = trim($slug);

    if (empty($name) || empty($slug)) {
        throw new Exception('Jina na slug ni lazima.');
    }
    if (!preg_match('/^[a-z0-9_]+$/', $slug)) {
        throw new Exception('Slug inaweza kuwa na herufi ndogo, nambari, na _ tu.');
    }
    if ($durationSeconds < 60) {
        throw new Exception('Muda lazima uwe angalau sekunde 60.');
    }
    if ($price < 0) {
        throw new Exception('Bei si sahihi.');
    }

    $db = getDB();

    // Check slug uniqueness
    $stmt = $db->prepare("SELECT id FROM packages WHERE slug = :slug");
    $stmt->execute([':slug' => $slug]);
    if ($stmt->fetch()) {
        throw new Exception('Slug tayari imetumika.');
    }

    // Get max sort order
    $maxSort = $db->query("SELECT COALESCE(MAX(sort_order), 0) FROM packages")->fetchColumn();

    $stmt = $db->prepare("
        INSERT INTO packages (name, slug, duration_seconds, price, bandwidth_mbps, data_quota_mb, description, is_active, sort_order, created_by)
        VALUES (:name, :slug, :duration, :price, :bw, :quota, :desc, 1, :sort, :created_by)
    ");
    $stmt->execute([
        ':name'        => $name,
        ':slug'        => $slug,
        ':duration'    => $durationSeconds,
        ':price'       => $price,
        ':bw'          => $bandwidthMbps,
        ':quota'       => $dataQuotaMb,
        ':desc'        => $description,
        ':sort'        => $maxSort + 1,
        ':created_by'  => $createdBy,
    ]);

    $id = (int) $db->lastInsertId();
    writeAuditLog('package_created', $createdBy, 'package', (string) $id, ['name' => $name, 'slug' => $slug]);
    return $id;
}

/**
 * Update a package
 */
function updatePackage(int $id, array $data, ?int $adminUserId = null): bool {
    $db = getDB();

    $sets = [];
    $params = [':id' => $id];

    $allowed = ['name', 'duration_seconds', 'price', 'bandwidth_mbps', 'data_quota_mb', 'description', 'is_active', 'sort_order'];

    foreach ($allowed as $field) {
        if (array_key_exists($field, $data)) {
            $sets[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }

    if (empty($sets)) {
        return false;
    }

    $sets[] = "updated_at = CURRENT_TIMESTAMP";
    $sql = "UPDATE packages SET " . implode(', ', $sets) . " WHERE id = :id";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() > 0) {
        writeAuditLog('package_updated', $adminUserId, 'package', (string) $id, $data);
        return true;
    }
    return false;
}

/**
 * Soft-delete a package (marks as deleted, no data is lost)
 */
function deletePackage(int $id, ?int $adminUserId = null): bool {
    $db = getDB();

    // Check package exists and isn't already deleted
    $stmt = $db->prepare("SELECT id, name FROM packages WHERE id = :id AND is_deleted = false");
    $stmt->execute([':id' => $id]);
    $pkg = $stmt->fetch();
    if (!$pkg) {
        throw new Exception('Package haikupatikana.');
    }

    // Soft delete: deactivate + mark deleted
    $stmt = $db->prepare("
        UPDATE packages
        SET is_active = false, is_deleted = true, updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $stmt->execute([':id' => $id]);

    writeAuditLog('package_soft_deleted', $adminUserId, 'package', (string) $id, ['name' => $pkg['name']]);
    return true;
}

/**
 * Restore a soft-deleted package
 */
function restorePackage(int $id, ?int $adminUserId = null): bool {
    $db = getDB();
    $stmt = $db->prepare("
        UPDATE packages
        SET is_deleted = false, updated_at = CURRENT_TIMESTAMP
        WHERE id = :id AND is_deleted = true
    ");
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() > 0) {
        writeAuditLog('package_restored', $adminUserId, 'package', (string) $id);
        return true;
    }
    return false;
}

/**
 * Activate a package
 */
function activatePackage(int $id, ?int $adminUserId = null): bool {
    return updatePackage($id, ['is_active' => 1], $adminUserId);
}

/**
 * Deactivate a package
 */
function deactivatePackage(int $id, ?int $adminUserId = null): bool {
    return updatePackage($id, ['is_active' => 0], $adminUserId);
}

// ── Query Functions ─────────────────────────────────────────────

/**
 * Get a package by ID
 */
function getPackageById(int $id, bool $includeDeleted = false): ?array {
    $db = getDB();
    $sql = "SELECT * FROM packages WHERE id = :id";
    if (!$includeDeleted) $sql .= " AND is_deleted = false";
    $stmt = $db->prepare($sql);
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Get a package by slug
 */
function getPackageBySlug(string $slug, bool $includeDeleted = false): ?array {
    $db = getDB();
    $sql = "SELECT * FROM packages WHERE slug = :slug";
    if (!$includeDeleted) $sql .= " AND is_deleted = false";
    $stmt = $db->prepare($sql);
    $stmt->execute([':slug' => $slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Get all packages (admin view — includes inactive)
 */
function getAllPackages(?bool $activeOnly = null, int $limit = 100, int $offset = 0, bool $includeDeleted = false): array {
    $db = getDB();

    $sql = "SELECT p.*, u.username AS created_by_username FROM packages p LEFT JOIN users u ON p.created_by = u.id WHERE 1=1";
    $params = [];

    if (!$includeDeleted) {
        $sql .= " AND p.is_deleted = false";
    }

    if ($activeOnly !== null) {
        $sql .= " AND p.is_active = :active";
        $params[':active'] = $activeOnly ? 1 : 0;
    }

    $sql .= " ORDER BY p.sort_order ASC, p.id ASC LIMIT :limit OFFSET :offset";

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
 * Get active packages (seller view)
 */
function getActivePackages(): array {
    return getAllPackages(true);
}

/**
 * Count packages
 */
function countPackages(?bool $activeOnly = null, bool $includeDeleted = false): int {
    $db = getDB();

    $sql = "SELECT COUNT(*) FROM packages WHERE 1=1";
    $params = [];

    if (!$includeDeleted) {
        $sql .= " AND is_deleted = false";
    }

    if ($activeOnly !== null) {
        $sql .= " AND is_active = :active";
        $params[':active'] = $activeOnly ? 1 : 0;
    }

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    return (int) $stmt->fetchColumn();
}

/**
 * Get package stats (admin dashboard)
 */
function getPackageStats(): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN is_active = true AND is_deleted = false THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN is_active = false AND is_deleted = false THEN 1 ELSE 0 END) AS inactive,
            SUM(CASE WHEN is_deleted = true THEN 1 ELSE 0 END) AS deleted
        FROM packages
    ");
    $stmt->execute();
    return $stmt->fetch();
}

/**
 * Get package popularity (how many vouchers generated per package)
 */
function getPackagePopularity(): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT
            p.id,
            p.name,
            p.slug,
            p.price,
            COUNT(v.id) AS voucher_count,
            COALESCE(SUM(CASE WHEN v.status = 'unused' THEN 1 ELSE 0 END), 0) AS unused_count,
            COALESCE(SUM(CASE WHEN v.status = 'active' THEN 1 ELSE 0 END), 0) AS active_count,
            COALESCE(SUM(CASE WHEN v.status = 'expired' THEN 1 ELSE 0 END), 0) AS expired_count
        FROM packages p
        LEFT JOIN vouchers v ON v.plan_name = p.name
        WHERE p.is_active = true AND p.is_deleted = false
        GROUP BY p.id, p.name, p.slug, p.price
        ORDER BY voucher_count DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Convert package to PLANS-compatible format (for backward compatibility)
 */
function packageToPlan(array $pkg): array {
    return [
        'name'             => $pkg['name'],
        'duration_seconds' => (int) $pkg['duration_seconds'],
        'price'            => (float) $pkg['price'],
    ];
}

/**
 * Get all active packages as PLANS-compatible array (keyed by slug)
 */
function getActivePackagesAsPlans(): array {
    $packages = getActivePackages();
    $plans = [];
    foreach ($packages as $pkg) {
        $plans[$pkg['slug']] = packageToPlan($pkg);
    }
    return $plans;
}
