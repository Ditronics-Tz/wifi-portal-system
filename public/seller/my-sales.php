<?php
require_once dirname(__DIR__, 2) . '/src/auth.php';
require_once dirname(__DIR__, 2) . '/src/sales_service.php';
require_once dirname(__DIR__, 2) . '/src/voucher_service.php';
startAppSession();
requireSellerOrAdmin();

$sellerId = getCurrentUserId();
$sellerUsername = getCurrentUsername();
$search = trim($_GET['search'] ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$sales = $sellerId ? getSellerSales($sellerId, $dateFrom ?: null, $dateTo ?: null, $search ?: null, $perPage, $offset) : [];
$totalSales = $sellerId ? countSellerSales($sellerId, $dateFrom ?: null, $dateTo ?: null, $search ?: null) : 0;
$totalPages = ceil($totalSales / $perPage);
$periodStats = $sellerId ? getSellerAllTimeStats($sellerId) : ['total_sales' => 0, 'total_revenue' => 0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Sales - Seller</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <div class="admin-wrapper">
        <header class="admin-header">
            <div class="admin-header-inner">
                <a href="/seller/dashboard.php" class="admin-logo"><img src="/assets/DITRONICS-COMPANY-LOGO.png" alt="Ditronics" style="height:28px;width:auto;"><span class="admin-logo-text">WiFi Voucher Seller</span></a>
                <nav class="admin-nav">
                    <a href="/seller/dashboard.php">Dashboard</a>
                    <a href="/seller/generate.php">Generate</a>

                    <a href="/seller/my-sales.php" class="active">My Sales</a>
                </nav>
                <div class="admin-user">
                    <div class="admin-user-avatar"><?php echo strtoupper(substr($sellerUsername, 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($sellerUsername); ?></span>
                    <a href="/seller/logout.php" style="color: var(--text-tertiary); text-decoration: none; font-size: var(--text-xs);">Logout</a>
                </div>
            </div>
        </header>
        <main class="admin-content">
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-value"><?php echo number_format($totalSales); ?></div><div class="stat-label">Total Sales</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo number_format($periodStats['total_revenue'] ?? 0); ?></div><div class="stat-label">Total Revenue (TZS)</div></div>
            </div>

            <div class="admin-card">
                <div class="admin-card-header"><h2 class="admin-card-title">My Sales List</h2></div>
                <form method="GET" action="" class="filters-bar">
                    <input type="text" name="search" class="filter-input" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                    <input type="date" name="date_from" class="filter-input" style="min-width: 140px;" value="<?php echo htmlspecialchars($dateFrom); ?>">
                    <input type="date" name="date_to" class="filter-input" style="min-width: 140px;" value="<?php echo htmlspecialchars($dateTo); ?>">
                    <button type="submit" class="btn btn-secondary btn-small">Search</button>
                    <?php if ($search || $dateFrom || $dateTo): ?><a href="/seller/my-sales.php" class="btn btn-secondary btn-small" style="text-decoration: none;">Clear</a><?php endif; ?>
                </form>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead><tr><th>Voucher</th><th>Plan</th><th>Price</th><th>Date</th></tr></thead>
                        <tbody>
                            <?php if (empty($sales)): ?><tr><td colspan="6" style="text-align: center; color: var(--text-tertiary); padding: var(--space-8);">No sales</td></tr>
                            <?php else: foreach ($sales as $sale): ?>
                            <tr>
                                <td><?php echo renderVoucherCode($sale['voucher_code'], true); ?></td>
                                <td><?php echo htmlspecialchars($sale['plan_name']); ?></td>
                                <td style="font-weight: 500;"><?php echo number_format($sale['price']); ?></td>
                                <td style="font-size: var(--text-sm);"><?php echo date('d/m/Y H:i', strtotime($sale['sold_at'])); ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?><a href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>">Back</a><?php endif; ?>
                    <?php for ($i = max(1, $page-2); $i <= min($totalPages, $page+2); $i++): ?><a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>" class="<?php echo $i===$page?'active':''; ?>"><?php echo $i; ?></a><?php endfor; ?>
                    <?php if ($page < $totalPages): ?><a href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>">Next</a><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script src="/assets/admin.js"></script>
</body>
</html>
