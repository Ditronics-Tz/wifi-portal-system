<?php
require_once dirname(__DIR__, 2) . '/src/auth.php';
require_once dirname(__DIR__, 2) . '/src/voucher_service.php';
require_once dirname(__DIR__, 2) . '/src/sales_service.php';
startAppSession();
requireSellerOrAdmin();

$sellerId = getCurrentUserId();
$sellerUsername = getCurrentUsername();
$todayStats = $sellerId ? getSellerTodayStats($sellerId) : ['sales_count' => 0, 'total_revenue' => 0];
$allTimeStats = $sellerId ? getSellerAllTimeStats($sellerId) : ['total_sales' => 0, 'total_revenue' => 0];
$stockByPlan = $sellerId ? getSellerVoucherStock($sellerId) : [];
$stockTotal = $sellerId ? getSellerVoucherStockTotal($sellerId) : 0;
$recentSales = $sellerId ? getSellerRecentSales($sellerId, 5) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Seller</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <div class="admin-wrapper">
        <header class="admin-header">
            <div class="admin-header-inner">
                <a href="/seller/dashboard.php" class="admin-logo"><img src="/assets/DITRONICS-COMPANY-LOGO.png" alt="Ditronics" style="height:28px;width:auto;"><span class="admin-logo-text">WiFi Voucher Seller</span></a>
                <nav class="admin-nav">
                    <a href="/seller/dashboard.php" class="active">Dashboard</a>
                    <a href="/seller/generate.php">Generate</a>

                    <a href="/seller/my-sales.php">My Sales</a>
                </nav>
                <div class="admin-user">
                    <div class="admin-user-avatar"><?php echo strtoupper(substr($sellerUsername, 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($sellerUsername); ?></span>
                    <a href="/seller/logout.php" style="color: var(--text-tertiary); text-decoration: none; font-size: var(--text-xs);">Logout</a>
                </div>
            </div>
        </header>
        <main class="admin-content">
            <?php if (hasRole('admin')): ?>
                <div class="alert alert-success"><span>Admin Mode — <a href="/admin/dashboard.php">Back to Admin Dashboard</a></span></div>
            <?php endif; ?>

            <div style="margin-bottom: var(--space-6);">
                <h1 style="font-size: var(--text-xl); font-weight: 700; margin-bottom: var(--space-1);">Welcome, <?php echo htmlspecialchars($_SESSION['seller_full_name'] ?? $sellerUsername); ?></h1>
                <p style="color: var(--text-secondary); font-size: var(--text-base);">Overview of your sales and vouchers.</p>
            </div>

            <div class="stats-grid">
                <div class="stat-card"><div class="stat-value"><?php echo number_format($todayStats['sales_count'] ?? 0); ?></div><div class="stat-label">Sales Today</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo number_format($todayStats['total_revenue'] ?? 0); ?></div><div class="stat-label">Revenue Today (TZS)</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo number_format($stockTotal); ?></div><div class="stat-label">Voucher Stock</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo number_format($allTimeStats['total_revenue'] ?? 0); ?></div><div class="stat-label">Total Revenue</div></div>
            </div>

            <div class="admin-card">
                <div class="admin-card-header"><h2 class="admin-card-title">Quick Actions</h2></div>
                <div style="display: flex; gap: var(--space-2); flex-wrap: wrap;">
                    <a href="/seller/generate.php" class="btn btn-primary btn-small" style="text-decoration: none;">Generate Vouchers</a>
                    <a href="/seller/my-sales.php" class="btn btn-secondary btn-small" style="text-decoration: none;">My Sales</a>
                </div>
            </div>

            <?php if (!empty($stockByPlan)): ?>
            <div class="admin-card">
                <div class="admin-card-header"><h2 class="admin-card-title">Voucher Stock</h2></div>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead><tr><th>Plan</th><th>Count</th></tr></thead>
                        <tbody><?php foreach ($stockByPlan as $stock): ?><tr><td style="font-weight: 500;"><?php echo htmlspecialchars($stock['plan_name']); ?></td><td><span class="badge badge-unused"><?php echo $stock['count']; ?> voucher(s)</span></td></tr><?php endforeach; ?></tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <div class="admin-card">
                <div class="admin-card-header">
                    <h2 class="admin-card-title">Recent Sales</h2>
                    <a href="/seller/my-sales.php" class="btn btn-secondary btn-tiny" style="text-decoration: none;">View All</a>
                </div>
                <?php if (empty($recentSales)): ?>
                    <p style="text-align: center; color: var(--text-tertiary); padding: var(--space-8);">You don't have any sales yet. <a href="/seller/record-sale.php">Record your first sale</a></p>
                <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead><tr><th>Voucher</th><th>Plan</th><th>Price</th><th>Date</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentSales as $sale): ?>
                            <tr>
                                <td><?php echo renderVoucherCode($sale['voucher_code'], true); ?></td>
                                <td><?php echo htmlspecialchars($sale['plan_name']); ?></td>
                                <td style="font-weight: 500;"><?php echo number_format($sale['price']); ?></td>
                                <td style="font-size: var(--text-sm);"><?php echo date('d/m H:i', strtotime($sale['sold_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script src="/assets/admin.js"></script>
</body>
</html>
