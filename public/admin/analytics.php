<?php
require_once dirname(__DIR__, 2) . '/src/auth.php';
require_once dirname(__DIR__, 2) . '/src/sales_service.php';
require_once dirname(__DIR__, 2) . '/src/user_service.php';
startAppSession();
requireAdmin();

$adminUsername = getCurrentUsername();
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
?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - Admin</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <div class="admin-wrapper">
        <header class="admin-header">
            <div class="admin-header-inner">
                <a href="/admin/dashboard.php" class="admin-logo"><img src="/assets/DITRONICS-COMPANY-LOGO.png" alt="Ditronics" style="height:28px;width:auto;"><span class="admin-logo-text">WiFi Voucher Admin</span></a>
                <nav class="admin-nav">
                    <a href="/admin/dashboard.php">Dashboard</a>
                    <a href="/admin/sellers.php">Sellers</a>
                    <a href="/admin/packages.php">Packages</a>
                    <a href="/admin/analytics.php" class="active">Analytics</a>
                    <a href="/admin/generate.php">Generate</a>
                </nav>
                <div class="admin-user">
                    <div class="admin-user-avatar"><?php echo strtoupper(substr($adminUsername, 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($adminUsername); ?></span>
                    <a href="/admin/logout.php" style="color: var(--text-tertiary); text-decoration: none; font-size: var(--text-xs);">Toka</a>
                </div>
            </div>
        </header>
        <main class="admin-content">
            <!-- Date Filter -->
            <div class="admin-card">
                <div style="display: flex; gap: var(--space-2); flex-wrap: wrap; align-items: center;">
                    <span style="font-weight: 500; color: var(--text-secondary); font-size: var(--text-sm);">Ripoti ya:</span>
                    <a href="?preset=today" class="btn btn-tiny <?php echo (isset($_GET['preset']) && $_GET['preset'] === 'today') ? 'btn-primary' : 'btn-secondary'; ?>" style="text-decoration: none;">Leo</a>
                    <a href="?preset=week" class="btn btn-tiny <?php echo (isset($_GET['preset']) && $_GET['preset'] === 'week') ? 'btn-primary' : 'btn-secondary'; ?>" style="text-decoration: none;">Wiki</a>
                    <a href="?preset=month" class="btn btn-tiny <?php echo (isset($_GET['preset']) && $_GET['preset'] === 'month') ? 'btn-primary' : 'btn-secondary'; ?>" style="text-decoration: none;">Mwezi</a>
                    <form method="GET" style="display: flex; gap: var(--space-2); align-items: center;">
                        <input type="date" name="date_from" class="filter-input" style="min-width: 130px; padding: 4px 8px; font-size: var(--text-xs);" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        <span style="color: var(--text-tertiary);">—</span>
                        <input type="date" name="date_to" class="filter-input" style="min-width: 130px; padding: 4px 8px; font-size: var(--text-xs);" value="<?php echo htmlspecialchars($dateTo); ?>">
                        <button type="submit" class="btn btn-tiny btn-secondary">Ripoti</button>
                    </form>
                    <?php if ($dateFrom || $dateTo): ?><a href="/admin/analytics.php" class="btn btn-tiny btn-secondary" style="text-decoration: none;">Ondoa</a><?php endif; ?>
                </div>
            </div>

            <!-- Overview -->
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-value"><?php echo number_format($systemStats['total_revenue'] ?? 0); ?></div><div class="stat-label">Jumla ya Mapato (TZS)</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo number_format($systemStats['total_sales'] ?? 0); ?></div><div class="stat-label">Jumla ya Mauzo</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo $sellerSummary['active_sellers'] ?? 0; ?></div><div class="stat-label">Sellers Hai</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo number_format($buyerStats['unique_phone_buyers'] ?? 0); ?></div><div class="stat-label">Wateja wa Kipekee</div></div>
            </div>

            <!-- Period Stats -->
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-value"><?php echo number_format($systemStats['today_sales'] ?? 0); ?></div><div class="stat-label">Mauzo Leo</div><div style="font-size: var(--text-sm); color: var(--text-secondary); margin-top: var(--space-1);"><?php echo number_format($systemStats['today_revenue'] ?? 0); ?> TZS</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo number_format($systemStats['week_sales'] ?? 0); ?></div><div class="stat-label">Wiki Hii</div><div style="font-size: var(--text-sm); color: var(--text-secondary); margin-top: var(--space-1);"><?php echo number_format($systemStats['week_revenue'] ?? 0); ?> TZS</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo number_format($systemStats['month_sales'] ?? 0); ?></div><div class="stat-label">Mwezi Huu</div><div style="font-size: var(--text-sm); color: var(--text-secondary); margin-top: var(--space-1);"><?php echo number_format($systemStats['month_revenue'] ?? 0); ?> TZS</div></div>
            </div>

            <div class="grid-2col">
                <!-- Sales by Plan -->
                <div class="admin-card">
                    <div class="admin-card-header"><h2 class="admin-card-title">Mauzo kwa Mpango</h2></div>
                    <?php if (empty($salesByPlan)): ?><p style="text-align: center; color: var(--text-tertiary); padding: var(--space-5);">Hakuna data</p>
                    <?php else: ?>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead><tr><th>Mpango</th><th>Mauzo</th><th>Mapato</th></tr></thead>
                            <tbody><?php foreach ($salesByPlan as $plan): ?><tr><td style="font-weight: 500;"><?php echo htmlspecialchars($plan['plan_name']); ?></td><td><?php echo number_format($plan['count']); ?></td><td style="font-weight: 500;"><?php echo number_format($plan['revenue']); ?></td></tr><?php endforeach; ?></tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Sales by Seller -->
                <div class="admin-card">
                    <div class="admin-card-header"><h2 class="admin-card-title">Utendaji wa Sellers</h2></div>
                    <?php if (empty($salesBySeller)): ?><p style="text-align: center; color: var(--text-tertiary); padding: var(--space-5);">Hakuna data</p>
                    <?php else: ?>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead><tr><th>Seller</th><th>Mauzo</th><th>Mapato</th></tr></thead>
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
                <div class="admin-card-header"><h2 class="admin-card-title">Mauzo ya Kila Siku (Siku <?php echo $days; ?>)</h2></div>
                <?php if (empty($dailyTrend)): ?><p style="text-align: center; color: var(--text-tertiary); padding: var(--space-8);">Hakuna data ya mauzo bado.</p>
                <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead><tr><th>Tarehe</th><th>Mauzo</th><th>Mapato (TZS)</th><th style="width: 30%;">Biashara</th></tr></thead>
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
                <div class="admin-card-header"><h2 class="admin-card-title">Mauzo ya Hivi Karibuni</h2></div>
                <?php if (empty($recentSales)): ?><p style="text-align: center; color: var(--text-tertiary); padding: var(--space-8);">Hakuna mauzo bado.</p>
                <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead><tr><th>Voucher</th><th>Mpango</th><th>Bei</th><th>Seller</th><th>Mteja</th><th>Tarehe</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentSales as $sale): ?>
                            <tr>
                                <td class="code-cell"><?php echo htmlspecialchars($sale['voucher_code']); ?></td>
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
        </main>
    </div>
</body>
</html>
