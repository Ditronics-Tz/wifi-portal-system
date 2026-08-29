<?php
require_once dirname(__DIR__, 2) . '/src/auth.php';
require_once dirname(__DIR__, 2) . '/src/voucher_service.php';
require_once dirname(__DIR__, 2) . '/src/sales_service.php';
require_once dirname(__DIR__, 2) . '/src/user_service.php';
require_once dirname(__DIR__, 2) . '/src/package_service.php';
startAppSession();
requireAdmin();

$stats = countVouchersByStatus();
$adminStats = getAdminDashboardStats();
$recentSales = getRecentSales(5);
$pkgPopularity = getPackagePopularity();
$usedVouchers = ($stats['active'] ?? 0) + ($stats['expired'] ?? 0);
$activePage = 'dashboard';
$pageTitle = 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Admin</title>
    <?php require dirname(__DIR__, 2) . '/src/theme_init.php'; ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('/assets/style.css')); ?>">
</head>
<body>
<?php require dirname(__DIR__, 2) . '/src/admin_header.php'; ?>
            <div class="section-header">
                <h1>Dashboard</h1>
                <p>Overview of the whole system — sales, staff, packages, and vouchers.</p>
            </div>

            <!-- Primary KPIs -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon-wrap icon-secondary"><?php echo hi('Coins01Icon', 20); ?></div>
                        <span class="stat-badge badge-up">Today</span>
                    </div>
                    <div class="stat-value"><?php echo number_format($adminStats['sales']['today_revenue'] ?? 0); ?></div>
                    <div class="stat-label">Revenue Today</div>
                    <div class="stat-description">TZS from <?php echo number_format($adminStats['sales']['today_sales'] ?? 0); ?> sales today</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header"><div class="stat-icon-wrap icon-secondary"><?php echo hi('AnalyticsUpIcon', 20); ?></div></div>
                    <div class="stat-value"><?php echo number_format($adminStats['sales']['total_revenue'] ?? 0); ?></div>
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-description">TZS from <?php echo number_format($adminStats['sales']['total_sales'] ?? 0); ?> sales overall</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header"><div class="stat-icon-wrap icon-primary"><?php echo hi('Ticket01Icon', 20); ?></div></div>
                    <div class="stat-value"><?php echo number_format($usedVouchers); ?></div>
                    <div class="stat-label">Used Vouchers</div>
                    <div class="stat-description">Active: <?php echo number_format($stats['active'] ?? 0); ?> · Expired: <?php echo number_format($stats['expired'] ?? 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header"><div class="stat-icon-wrap icon-success"><?php echo hi('CheckmarkCircle01Icon', 20); ?></div></div>
                    <div class="stat-value"><?php echo number_format($adminStats['vouchers']['unused'] ?? 0); ?></div>
                    <div class="stat-label">Voucher Stock</div>
                    <div class="stat-description">Unused vouchers, ready for sale</div>
                </div>
            </div>

            <!-- Package Popularity & Recent Sales -->
            <div class="grid-2col">
                <div class="admin-card">
                    <div class="admin-card-header">
                        <div class="admin-card-header-text">
                            <h2 class="admin-card-title">Popular Packages</h2>
                            <p class="admin-card-subtitle">Vouchers generated per package</p>
                        </div>
                        <a href="/admin/packages.php" class="btn btn-ghost btn-tiny">Manage</a>
                    </div>
                    <?php if (empty($pkgPopularity)): ?>
                        <p style="text-align: center; color: var(--text-tertiary); padding: var(--space-6);">No data yet.</p>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead><tr><th>Package</th><th>Vouchers</th><th>Active</th><th>Expired</th></tr></thead>
                                <tbody>
                                    <?php foreach ($pkgPopularity as $pkg): ?>
                                    <tr>
                                        <td style="font-weight: 500;"><?php echo htmlspecialchars($pkg['name']); ?></td>
                                        <td><?php echo number_format($pkg['voucher_count']); ?></td>
                                        <td><span class="badge badge-active"><?php echo $pkg['active_count']; ?></span></td>
                                        <td><span class="badge badge-expired"><?php echo $pkg['expired_count']; ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="admin-card">
                    <div class="admin-card-header">
                        <div class="admin-card-header-text">
                            <h2 class="admin-card-title">Recent Sales</h2>
                            <p class="admin-card-subtitle">Last 5 sales</p>
                        </div>
                        <a href="/admin/analytics.php" class="btn btn-ghost btn-tiny">View All</a>
                    </div>
                    <?php if (empty($recentSales)): ?>
                        <p style="text-align: center; color: var(--text-tertiary); padding: var(--space-6);">No sales yet.</p>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead><tr><th>Seller</th><th>Voucher</th><th>Price</th></tr></thead>
                                <tbody>
                                    <?php foreach ($recentSales as $sale): ?>
                                    <tr>
                                        <td style="font-weight: 500;"><?php echo htmlspecialchars($sale['seller_username']); ?></td>
                                <td><?php echo renderVoucherCode($sale['voucher_code'], true); ?></td>
                                        <td style="font-weight: 600;"><?php echo number_format($sale['price']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
<?php require dirname(__DIR__, 2) . '/src/admin_footer.php'; ?>
</body>
</html>
