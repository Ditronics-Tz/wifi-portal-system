<?php
require_once dirname(__DIR__, 2) . '/src/auth.php';
require_once dirname(__DIR__, 2) . '/src/sales_service.php';
require_once dirname(__DIR__, 2) . '/src/user_service.php';
require_once dirname(__DIR__, 2) . '/src/voucher_service.php';
startAppSession();
requireAdmin();

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$days = intval($_GET['days'] ?? 30);

if (isset($_GET['preset'])) {
    switch ($_GET['preset']) {
        case 'today': $dateFrom = $dateTo = date('Y-m-d'); break;
        case 'week': $dateFrom = date('Y-m-d', strtotime('-7 days')); $dateTo = date('Y-m-d'); break;
        case 'month': $dateFrom = date('Y-m-d', strtotime('-30 days')); $dateTo = date('Y-m-d'); break;
    }
}

$systemStats = getSystemSalesStats();
$salesByPlan = getSalesByPlan($dateFrom ?: null, $dateTo ?: null);
$salesBySeller = getSalesBySeller($dateFrom ?: null, $dateTo ?: null);
$dailyTrend = getDailySalesTrend($days);
$buyerStats = getUniqueBuyerCount();
$recentSales = getRecentSales(10);
$sellerSummary = getSellerSummaryStats();
$activePage = 'analytics';
$pageTitle = 'Analytics';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - Admin</title>
    <?php require dirname(__DIR__, 2) . '/src/theme_init.php'; ?>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php require dirname(__DIR__, 2) . '/src/admin_header.php'; ?>
            <!-- Date Filter -->
            <div class="admin-card">
                <div style="display: flex; gap: var(--space-2); flex-wrap: wrap; align-items: center;">
                    <span style="font-weight: 500; color: var(--text-secondary); font-size: var(--text-sm);">Report for:</span>
                    <a href="?preset=today" class="btn btn-tiny <?php echo (isset($_GET['preset']) && $_GET['preset'] === 'today') ? 'btn-primary' : 'btn-secondary'; ?>" style="text-decoration: none;">Today</a>
                    <a href="?preset=week" class="btn btn-tiny <?php echo (isset($_GET['preset']) && $_GET['preset'] === 'week') ? 'btn-primary' : 'btn-secondary'; ?>" style="text-decoration: none;">Week</a>
                    <a href="?preset=month" class="btn btn-tiny <?php echo (isset($_GET['preset']) && $_GET['preset'] === 'month') ? 'btn-primary' : 'btn-secondary'; ?>" style="text-decoration: none;">Month</a>
                    <form method="GET" style="display: flex; gap: var(--space-2); align-items: center;">
                        <input type="date" name="date_from" class="filter-input" style="min-width: 130px; padding: 4px 8px; font-size: var(--text-xs);" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        <span style="color: var(--text-tertiary);">—</span>
                        <input type="date" name="date_to" class="filter-input" style="min-width: 130px; padding: 4px 8px; font-size: var(--text-xs);" value="<?php echo htmlspecialchars($dateTo); ?>">
                        <button type="submit" class="btn btn-tiny btn-secondary">Filter</button>
                    </form>
                    <?php if ($dateFrom || $dateTo): ?><a href="/admin/analytics.php" class="btn btn-tiny btn-secondary" style="text-decoration: none;">Clear</a><?php endif; ?>
                </div>
            </div>

            <!-- Overview -->
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-value"><?php echo number_format($systemStats['total_revenue'] ?? 0); ?></div><div class="stat-label">Total Revenue (TZS)</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo number_format($systemStats['total_sales'] ?? 0); ?></div><div class="stat-label">Total Sales</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo $sellerSummary['active_sellers'] ?? 0; ?></div><div class="stat-label">Active Sellers</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo number_format($buyerStats['unique_phone_buyers'] ?? 0); ?></div><div class="stat-label">Unique Customers</div></div>
            </div>

            <!-- Period Stats -->
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-value"><?php echo number_format($systemStats['today_sales'] ?? 0); ?></div><div class="stat-label">Sales Today</div><div style="font-size: var(--text-sm); color: var(--text-secondary); margin-top: var(--space-1);"><?php echo number_format($systemStats['today_revenue'] ?? 0); ?> TZS</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo number_format($systemStats['week_sales'] ?? 0); ?></div><div class="stat-label">This Week</div><div style="font-size: var(--text-sm); color: var(--text-secondary); margin-top: var(--space-1);"><?php echo number_format($systemStats['week_revenue'] ?? 0); ?> TZS</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo number_format($systemStats['month_sales'] ?? 0); ?></div><div class="stat-label">This Month</div><div style="font-size: var(--text-sm); color: var(--text-secondary); margin-top: var(--space-1);"><?php echo number_format($systemStats['month_revenue'] ?? 0); ?> TZS</div></div>
            </div>

            <div class="grid-2col">
                <!-- Sales by Plan -->
                <div class="admin-card">
                    <div class="admin-card-header"><h2 class="admin-card-title">Sales by Plan</h2></div>
                    <?php if (empty($salesByPlan)): ?><p style="text-align: center; color: var(--text-tertiary); padding: var(--space-5);">No data</p>
                    <?php else: ?>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead><tr><th>Plan</th><th>Sales</th><th>Revenue</th></tr></thead>
                            <tbody><?php foreach ($salesByPlan as $plan): ?><tr><td style="font-weight: 500;"><?php echo htmlspecialchars($plan['plan_name']); ?></td><td><?php echo number_format($plan['count']); ?></td><td style="font-weight: 500;"><?php echo number_format($plan['revenue']); ?></td></tr><?php endforeach; ?></tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Sales by Seller -->
                <div class="admin-card">
                    <div class="admin-card-header"><h2 class="admin-card-title">Seller Performance</h2></div>
                    <?php if (empty($salesBySeller)): ?><p style="text-align: center; color: var(--text-tertiary); padding: var(--space-5);">No data</p>
                    <?php else: ?>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead><tr><th>Seller</th><th>Sales</th><th>Revenue</th></tr></thead>
                            <tbody><?php foreach ($salesBySeller as $i => $seller): ?><tr>
                                <td style="font-weight: 500;"><?php echo htmlspecialchars($seller['username']); ?><?php if ($seller['full_name']): ?><br><small style="color: var(--text-tertiary);"><?php echo htmlspecialchars($seller['full_name']); ?></small><?php endif; ?></td>
                                <td><?php echo number_format($seller['sale_count']); ?></td>
                                <td style="font-weight: 500;"><?php echo number_format($seller['total_revenue']); ?></td>
                            </tr><?php endforeach; ?></tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Daily Trend -->
            <div class="admin-card">
                <div class="admin-card-header"><h2 class="admin-card-title">Daily Sales (Last <?php echo $days; ?> Days)</h2></div>
                <?php if (empty($dailyTrend)): ?><p style="text-align: center; color: var(--text-tertiary); padding: var(--space-8);">No sales data yet.</p>
                <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead><tr><th>Date</th><th>Sales</th><th>Revenue (TZS)</th><th style="width: 30%;">Trend</th></tr></thead>
                        <tbody>
                            <?php $maxRevenue = max(array_column($dailyTrend, 'revenue') ?: [1]);
                            foreach (array_reverse($dailyTrend) as $day):
                                $barWidth = $maxRevenue > 0 ? round(($day['revenue'] / $maxRevenue) * 100) : 0; ?>
                            <tr>
                                <td style="font-weight: 500;"><?php echo date('d/m/Y', strtotime($day['sale_date'])); ?></td>
                                <td><?php echo number_format($day['count']); ?></td>
                                <td style="font-weight: 500;"><?php echo number_format($day['revenue']); ?></td>
                                <td><div style="background: var(--color-gray-100); border-radius: 3px; height: 6px; overflow: hidden;"><div style="background: var(--color-gray-400); height: 100%; width: <?php echo $barWidth; ?>%; border-radius: 3px;"></div></div></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Recent Sales -->
            <div class="admin-card">
                <div class="admin-card-header"><h2 class="admin-card-title">Recent Sales</h2></div>
                <?php if (empty($recentSales)): ?><p style="text-align: center; color: var(--text-tertiary); padding: var(--space-8);">No sales yet.</p>
                <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead><tr><th>Voucher</th><th>Plan</th><th>Price</th><th>Seller</th><th>Customer</th><th>Date</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentSales as $sale): ?>
                            <tr>
                                <td><?php echo renderVoucherCode($sale['voucher_code'], true); ?></td>
                                <td><?php echo htmlspecialchars($sale['plan_name']); ?></td>
                                <td style="font-weight: 500;"><?php echo number_format($sale['price']); ?></td>
                                <td style="font-weight: 500;"><?php echo htmlspecialchars($sale['seller_username']); ?></td>
                                <td><?php echo htmlspecialchars($sale['buyer_phone'] ?? $sale['buyer_name'] ?? '—'); ?></td>
                                <td style="font-size: var(--text-sm);"><?php echo date('d/m H:i', strtotime($sale['sold_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
<?php require dirname(__DIR__, 2) . '/src/admin_footer.php'; ?>
</body>
</html>
