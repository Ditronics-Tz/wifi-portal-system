<?php
/**
 * Sales Service — Recording sales, querying history, and analytics
 * v2: Every voucher sale is permanently linked to the seller
 */

require_once __DIR__ . '/db.php';

// ── Record a Sale ───────────────────────────────────────────────

/**
 * Record a voucher sale transaction
 *
 * @param string      $voucherCode     The voucher code being sold
 * @param int         $sellerId        Seller performing the sale
 * @param string|null $buyerPhone      Buyer's phone (optional)
 * @param string|null $buyerName       Buyer's name (optional)
 * @param float|null  $customPrice     Override price (null = use plan price)
 * @param string      $paymentMethod   Payment method (default: 'cash')
 * @param string|null $paymentRef      Payment reference (for electronic payments)
 * @return int                         New sale ID
 * @throws Exception                   On validation or business rule violations
 */
function recordSale(
    string  $voucherCode,
    int     $sellerId,
    ?string $buyerPhone = null,
    ?string $buyerName = null,
    ?float  $customPrice = null,
    string  $paymentMethod = 'cash',
    ?string $paymentRef = null
): int {
    $db = getDB();

    try {
        $db->beginTransaction();

        // Validate voucher exists and is available for sale
        // Note: FOR UPDATE is PostgreSQL-specific; SQLite handles concurrency via WAL mode
        $stmt = $db->prepare("
            SELECT id, code, plan_name, price, status, seller_id
            FROM vouchers
            WHERE code = :code
        ");
        $stmt->execute([':code' => $voucherCode]);
        $voucher = $stmt->fetch();

        if (!$voucher) {
            throw new Exception('Voucher not found.');
        }

        if ($voucher['status'] !== 'unused') {
            throw new Exception('This voucher has already been used or has expired.');
        }

        // Check if already sold
        $stmt = $db->prepare("SELECT id FROM sales WHERE voucher_code = :code");
        $stmt->execute([':code' => $voucherCode]);
        if ($stmt->fetch()) {
            throw new Exception('This voucher has already been sold.');
        }

        if ($sellerId <= 0) {
            throw new Exception('Sale must be linked to a staff or admin account.');
        }

        $userCheck = $db->prepare("SELECT id FROM users WHERE id = :id");
        $userCheck->execute([':id' => $sellerId]);
        if (!$userCheck->fetch()) {
            throw new Exception('Sale must be linked to a staff or admin account.');
        }

        // Verify seller owns this voucher (0/null = admin stock, allowed)
        $voucherSellerId = (int) ($voucher['seller_id'] ?? 0);
        if ($voucherSellerId > 0 && $voucherSellerId !== $sellerId) {
            throw new Exception('This voucher does not belong to you.');
        }

        // Validate buyer phone if provided
        if (!empty($buyerPhone)) {
            require_once __DIR__ . '/user_service.php';
            $buyerPhone = validateAndFormatPhone($buyerPhone);
        } else {
            $buyerPhone = null;
        }

        // Use custom price or plan price
        $price = $customPrice !== null ? $customPrice : (float) $voucher['price'];
        if ($price < 0) {
            throw new Exception('Invalid price.');
        }

        // Create the sale record
        $stmt = $db->prepare("
            INSERT INTO sales (voucher_code, seller_id, buyer_phone, buyer_name, plan_name, price, payment_method, payment_reference)
            VALUES (:voucher_code, :seller_id, :buyer_phone, :buyer_name, :plan_name, :price, :payment_method, :payment_reference)
        ");
        $stmt->execute([
            ':voucher_code'      => $voucherCode,
            ':seller_id'         => $sellerId,
            ':buyer_phone'       => $buyerPhone,
            ':buyer_name'        => $buyerName ?: null,
            ':plan_name'         => $voucher['plan_name'],
            ':price'             => $price,
            ':payment_method'    => $paymentMethod,
            ':payment_reference' => $paymentRef,
        ]);

        $saleId = (int) $db->lastInsertId();

        $db->commit();

        writeAuditLog('sale_recorded', $sellerId, 'sale', (string) $saleId, [
            'voucher_code' => $voucherCode,
            'price'        => $price,
            'buyer_phone'  => $buyerPhone,
        ]);

        return $saleId;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

// ── Query Sales ─────────────────────────────────────────────────

/**
 * Get sales for a specific seller with filters
 */
function getSellerSales(
    int     $sellerId,
    ?string $dateFrom = null,
    ?string $dateTo = null,
    ?string $search = null,
    int     $limit = 50,
    int     $offset = 0
): array {
    $db = getDB();

    $sql = "
        SELECT s.*, v.status AS voucher_status
        FROM sales s
        LEFT JOIN vouchers v ON s.voucher_code = v.code
        WHERE s.seller_id = :seller_id
    ";
    $params = [':seller_id' => $sellerId];

    if ($dateFrom) {
        $sql .= " AND s.sold_at >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo) {
        $sql .= " AND s.sold_at <= :date_to";
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }
    if ($search) {
        $sql .= " AND (s.voucher_code LIKE :search OR s.buyer_phone LIKE :search OR s.buyer_name LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY s.sold_at DESC LIMIT :limit OFFSET :offset";

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
 * Count sales for a specific seller
 */
function countSellerSales(int $sellerId, ?string $dateFrom = null, ?string $dateTo = null, ?string $search = null): int {
    $db = getDB();

    $sql = "SELECT COUNT(*) FROM sales WHERE seller_id = :seller_id";
    $params = [':seller_id' => $sellerId];

    if ($dateFrom) {
        $sql .= " AND sold_at >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo) {
        $sql .= " AND sold_at <= :date_to";
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }
    if ($search) {
        $sql .= " AND (voucher_code LIKE :search OR buyer_phone LIKE :search OR buyer_name LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    return (int) $stmt->fetchColumn();
}

/**
 * Get all sales (admin view) with filters
 */
function getAllSales(
    ?int    $sellerId = null,
    ?string $dateFrom = null,
    ?string $dateTo = null,
    ?string $planName = null,
    ?string $search = null,
    int     $limit = 50,
    int     $offset = 0
): array {
    $db = getDB();

    $sql = "
        SELECT s.*, u.username AS seller_username, u.full_name AS seller_full_name, v.status AS voucher_status
        FROM sales s
        LEFT JOIN users u ON s.seller_id = u.id
        LEFT JOIN vouchers v ON s.voucher_code = v.code
        WHERE 1=1
    ";
    $params = [];

    if ($sellerId) {
        $sql .= " AND s.seller_id = :seller_id";
        $params[':seller_id'] = $sellerId;
    }
    if ($dateFrom) {
        $sql .= " AND s.sold_at >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo) {
        $sql .= " AND s.sold_at <= :date_to";
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }
    if ($planName) {
        $sql .= " AND s.plan_name = :plan_name";
        $params[':plan_name'] = $planName;
    }
    if ($search) {
        $sql .= " AND (s.voucher_code LIKE :search OR s.buyer_phone LIKE :search OR s.buyer_name LIKE :search OR u.username LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY s.sold_at DESC LIMIT :limit OFFSET :offset";

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
 * Count all sales (admin view)
 */
function countAllSales(?int $sellerId = null, ?string $dateFrom = null, ?string $dateTo = null, ?string $planName = null, ?string $search = null): int {
    $db = getDB();

    $sql = "SELECT COUNT(*) FROM sales s LEFT JOIN users u ON s.seller_id = u.id WHERE 1=1";
    $params = [];

    if ($sellerId) {
        $sql .= " AND s.seller_id = :seller_id";
        $params[':seller_id'] = $sellerId;
    }
    if ($dateFrom) {
        $sql .= " AND s.sold_at >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo) {
        $sql .= " AND s.sold_at <= :date_to";
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }
    if ($planName) {
        $sql .= " AND s.plan_name = :plan_name";
        $params[':plan_name'] = $planName;
    }
    if ($search) {
        $sql .= " AND (s.voucher_code LIKE :search OR s.buyer_phone LIKE :search OR s.buyer_name LIKE :search OR u.username LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    return (int) $stmt->fetchColumn();
}

// ── Seller Dashboard Stats ──────────────────────────────────────

/**
 * Get today's stats for a seller
 */
function getSellerTodayStats(int $sellerId): array {
    $db = getDB();
    $today = date('Y-m-d');

    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS sales_count,
            COALESCE(SUM(price), 0) AS total_revenue
        FROM sales
        WHERE seller_id = :seller_id
          AND DATE(sold_at) = :today
    ");
    $stmt->execute([':seller_id' => $sellerId, ':today' => $today]);
    return $stmt->fetch();
}

/**
 * Get all-time stats for a seller
 */
function getSellerAllTimeStats(int $sellerId): array {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS total_sales,
            COALESCE(SUM(price), 0) AS total_revenue
        FROM sales
        WHERE seller_id = :seller_id
    ");
    $stmt->execute([':seller_id' => $sellerId]);
    return $stmt->fetch();
}

/**
 * Get seller's voucher stock (generated but not sold)
 */
function getSellerVoucherStock(int $sellerId): array {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT
            v.plan_name,
            COUNT(*) AS count
        FROM vouchers v
        WHERE v.seller_id = :seller_id
          AND v.status = 'unused'
          AND v.code NOT IN (SELECT voucher_code FROM sales WHERE voucher_code = v.code)
        GROUP BY v.plan_name
        ORDER BY v.plan_name
    ");
    $stmt->execute([':seller_id' => $sellerId]);
    return $stmt->fetchAll();
}

/**
 * Get total unsold voucher count for a seller
 */
function getSellerVoucherStockTotal(int $sellerId): int {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM vouchers v
        WHERE v.seller_id = :seller_id
          AND v.status = 'unused'
          AND v.code NOT IN (SELECT voucher_code FROM sales WHERE voucher_code = v.code)
    ");
    $stmt->execute([':seller_id' => $sellerId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Get seller's recent sales (for dashboard)
 */
function getSellerRecentSales(int $sellerId, int $limit = 5): array {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT s.*, v.status AS voucher_status
        FROM sales s
        LEFT JOIN vouchers v ON s.voucher_code = v.code
        WHERE s.seller_id = :seller_id
        ORDER BY s.sold_at DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':seller_id', $sellerId);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

// ── Admin Analytics ─────────────────────────────────────────────

/**
 * Get system-wide sales stats (admin dashboard)
 */
function getSystemSalesStats(): array {
    $db = getDB();
    $today = date('Y-m-d');
    $weekAgo = date('Y-m-d', strtotime('-7 days'));
    $monthAgo = date('Y-m-d', strtotime('-30 days'));

    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS total_sales,
            COALESCE(SUM(price), 0) AS total_revenue,
            COUNT(CASE WHEN DATE(sold_at) = :today THEN 1 END) AS today_sales,
            COALESCE(SUM(CASE WHEN DATE(sold_at) = :today THEN price ELSE 0 END), 0) AS today_revenue,
            COUNT(CASE WHEN sold_at >= :week_ago THEN 1 END) AS week_sales,
            COALESCE(SUM(CASE WHEN sold_at >= :week_ago THEN price ELSE 0 END), 0) AS week_revenue,
            COUNT(CASE WHEN sold_at >= :month_ago THEN 1 END) AS month_sales,
            COALESCE(SUM(CASE WHEN sold_at >= :month_ago THEN price ELSE 0 END), 0) AS month_revenue
        FROM sales
    ");
    $stmt->execute([
        ':today'     => $today,
        ':week_ago'  => $weekAgo,
        ':month_ago' => $monthAgo,
    ]);
    return $stmt->fetch();
}

/**
 * Get sales by plan breakdown (admin)
 */
function getSalesByPlan(?string $dateFrom = null, ?string $dateTo = null): array {
    $db = getDB();

    $sql = "SELECT plan_name, COUNT(*) AS count, COALESCE(SUM(price), 0) AS revenue FROM sales WHERE 1=1";
    $params = [];

    if ($dateFrom) {
        $sql .= " AND sold_at >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo) {
        $sql .= " AND sold_at <= :date_to";
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }

    $sql .= " GROUP BY plan_name ORDER BY revenue DESC";

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * Get sales grouped by seller (admin — for performance comparison)
 */
function getSalesBySeller(?string $dateFrom = null, ?string $dateTo = null): array {
    $db = getDB();

    $sql = "
        SELECT
            u.id AS seller_id,
            u.username,
            u.full_name,
            COUNT(s.id) AS sale_count,
            COALESCE(SUM(s.price), 0) AS total_revenue,
            COUNT(DISTINCT s.voucher_code) AS vouchers_sold
        FROM users u
        LEFT JOIN sales s ON u.id = s.seller_id
    ";

    $conditions = ["u.role = 'seller'"];
    $params = [];

    if ($dateFrom) {
        $conditions[] = "s.sold_at >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo) {
        $conditions[] = "s.sold_at <= :date_to";
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }

    $sql .= " WHERE " . implode(' AND ', $conditions);
    $sql .= " GROUP BY u.id, u.username, u.full_name ORDER BY total_revenue DESC";

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * Get daily sales trend (for charts)
 */
function getDailySalesTrend(int $days = 30): array {
    $db = getDB();
    $startDate = date('Y-m-d', strtotime("-{$days} days"));

    $stmt = $db->prepare("
        SELECT
            DATE(sold_at) AS sale_date,
            COUNT(*) AS count,
            COALESCE(SUM(price), 0) AS revenue
        FROM sales
        WHERE sold_at >= :start_date
        GROUP BY DATE(sold_at)
        ORDER BY sale_date
    ");
    $stmt->execute([':start_date' => $startDate]);

    return $stmt->fetchAll();
}

/**
 * Get unique buyer count (registered + walk-in by phone)
 */
function getUniqueBuyerCount(): array {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT
            COUNT(DISTINCT buyer_phone) AS unique_phone_buyers,
            COUNT(DISTINCT buyer_id) AS unique_account_buyers,
            COUNT(*) AS total_transactions
        FROM sales
        WHERE buyer_phone IS NOT NULL OR buyer_id IS NOT NULL
    ");
    $stmt->execute();
    return $stmt->fetch();
}

/**
 * Get comprehensive admin dashboard stats
 */
function getAdminDashboardStats(): array {
    $db = getDB();

    $stats = [];

    // Seller stats
    $stmt = $db->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN is_active=true THEN 1 ELSE 0 END) AS active FROM users WHERE role='seller'");
    $stmt->execute();
    $sellerStats = $stmt->fetch();
    $stats['total_sellers'] = (int) ($sellerStats['total'] ?? 0);
    $stats['active_sellers'] = (int) ($sellerStats['active'] ?? 0);

    // Sales stats
    $stats['sales'] = getSystemSalesStats();

    // Voucher stats
    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status='unused' THEN 1 ELSE 0 END) AS unused,
            SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN status='expired' THEN 1 ELSE 0 END) AS expired
        FROM vouchers
    ");
    $stmt->execute();
    $stats['vouchers'] = $stmt->fetch();

    // Buyer stats
    $stats['buyers'] = getUniqueBuyerCount();

    return $stats;
}

/**
 * Get recent sales across all sellers (admin view)
 */
function getRecentSales(int $limit = 10): array {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT s.*, u.username AS seller_username, u.full_name AS seller_full_name, v.status AS voucher_status
        FROM sales s
        LEFT JOIN users u ON s.seller_id = u.id
        LEFT JOIN vouchers v ON s.voucher_code = v.code
        ORDER BY s.sold_at DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}
