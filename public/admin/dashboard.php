<?php
require_once dirname(__DIR__, 2) . '/src/auth.php';
require_once dirname(__DIR__, 2) . '/src/voucher_service.php';
require_once dirname(__DIR__, 2) . '/src/sales_service.php';
require_once dirname(__DIR__, 2) . '/src/user_service.php';
require_once dirname(__DIR__, 2) . '/src/package_service.php';
startAppSession();
requireAdmin();

$statusFilter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$sellerFilter = isset($_GET['seller_id']) ? intval($_GET['seller_id']) : null;
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'expire' && isset($_POST['code'])) {
        $code = preg_replace('/[^A-Z0-9]/', '', $_POST['code']);
        if (!empty($code)) { forceExpireVoucher($code); $message = "Voucher $code imekwishwa muda."; }
    }
}

$vouchers = getVouchers($statusFilter, $search, $sellerFilter, $perPage, $offset);
$stats = countVouchersByStatus();
$adminStats = getAdminDashboardStats();
$recentSales = getRecentSales(5);
$sellerList = getSellers(null, true, 100, 0);
$pkgStats = getPackageStats();
$pkgPopularity = getPackagePopularity();
$sellerPerf = getSalesBySeller();
$activePage = 'dashboard';
$pageTitle = 'Dashboard';
?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Admin</title>
    <?php require dirname(__DIR__, 2) . '/src/theme_init.php'; ?>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php require dirname(__DIR__, 2) . '/src/admin_header.php'; ?>
            <div class="section-header">
                <h1>Dashboard</h1>
                <p>Muhtasari wa mfumo wote — mauzo, sellers, packages, na voucher.</p>
            </div>

            <?php if (isset($message)): ?><div class="alert alert-success"><span><?php echo htmlspecialchars($message); ?></span></div><?php endif; ?>

            <!-- Primary KPIs -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon-wrap icon-secondary">💰</div>
                        <span class="stat-badge badge-up">Leo</span>
                    </div>
                    <div class="stat-value"><?php echo number_format($adminStats['sales']['today_revenue'] ?? 0); ?></div>
                    <div class="stat-label">Mapato Leo</div>
                    <div class="stat-description">TZS kutoka mauzo <?php echo number_format($adminStats['sales']['today_sales'] ?? 0); ?> leo</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon-wrap icon-accent">📊</div>
                        <span class="stat-badge badge-neutral">Mwezi</span>
                    </div>
                    <div class="stat-value"><?php echo number_format($adminStats['sales']['month_sales'] ?? 0); ?></div>
                    <div class="stat-label">Mauzo Mwezi Huu</div>
                    <div class="stat-description">Mapato: <?php echo number_format($adminStats['sales']['month_revenue'] ?? 0); ?> TZS</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon-wrap icon-success">👥</div>
                    </div>
                    <div class="stat-value"><?php echo $adminStats['active_sellers'] ?? 0; ?></div>
                    <div class="stat-label">Sellers Hai</div>
                    <div class="stat-description">Kati ya <?php echo $adminStats['total_sellers'] ?? 0; ?> jumla</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon-wrap icon-warning">📦</div>
                    </div>
                    <div class="stat-value"><?php echo $pkgStats['active'] ?? 0; ?></div>
                    <div class="stat-label">Packages Hai</div>
                    <div class="stat-description">Kati ya <?php echo $pkgStats['total'] ?? 0; ?> jumla</div>
                </div>
            </div>

            <!-- Secondary Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header"><div class="stat-icon-wrap icon-primary">🎫</div></div>
                    <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
                    <div class="stat-label">Jumla ya Voucher</div>
                    <div class="stat-description">Hazijatumika: <?php echo $stats['unused'] ?? 0; ?> · Zinafanya kazi: <?php echo $stats['active'] ?? 0; ?> · Zimekwisha: <?php echo $stats['expired'] ?? 0; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header"><div class="stat-icon-wrap icon-secondary">📈</div></div>
                    <div class="stat-value"><?php echo number_format($adminStats['sales']['total_revenue'] ?? 0); ?></div>
                    <div class="stat-label">Jumla ya Mapato</div>
                    <div class="stat-description">TZS kutoka mauzo <?php echo number_format($adminStats['sales']['total_sales'] ?? 0); ?> yote</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header"><div class="stat-icon-wrap icon-accent">🧑</div></div>
                    <div class="stat-value"><?php echo number_format($adminStats['buyers']['unique_phone_buyers'] ?? 0); ?></div>
                    <div class="stat-label">Wateja wa Kipekee</div>
                    <div class="stat-description">Waliopatikana kwa namba ya simu</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header"><div class="stat-icon-wrap icon-success">✓</div></div>
                    <div class="stat-value"><?php echo number_format($adminStats['vouchers']['unused'] ?? 0); ?></div>
                    <div class="stat-label">Voucher Stock</div>
                    <div class="stat-description">Voucher hazijatumika, tayari kwa mauzo</div>
                </div>
            </div>

            <div class="grid-2col">
                <!-- Quick Actions -->
                <div class="admin-card">
                    <div class="admin-card-header">
                        <div class="admin-card-header-text">
                            <h2 class="admin-card-title">Haraka</h2>
                            <p class="admin-card-subtitle">Vitendo vya haraka</p>
                        </div>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: var(--space-2);">
                        <a href="/admin/packages.php" class="btn btn-secondary btn-small" style="text-decoration: none; justify-content: flex-start;">📦 Simamia Packages</a>
                        <a href="/admin/sellers.php" class="btn btn-secondary btn-small" style="text-decoration: none; justify-content: flex-start;">👥 Simamia Sellers</a>
                        <a href="/admin/generate.php" class="btn btn-secondary btn-small" style="text-decoration: none; justify-content: flex-start;">🎫 Tengeneza Voucher</a>
                        <a href="/admin/analytics.php" class="btn btn-secondary btn-small" style="text-decoration: none; justify-content: flex-start;">📊 Ripoti na Analytics</a>
                    </div>
                </div>

                <!-- Top Sellers -->
                <div class="admin-card">
                    <div class="admin-card-header">
                        <div class="admin-card-header-text">
                            <h2 class="admin-card-title">Top Sellers</h2>
                            <p class="admin-card-subtitle">Kulingana na mauzo</p>
                        </div>
                        <a href="/admin/sellers.php" class="btn btn-ghost btn-tiny">Ona Wote</a>
                    </div>
                    <?php if (empty($sellerPerf)): ?>
                        <p style="text-align: center; color: var(--text-tertiary); padding: var(--space-6);">Hakuna mauzo bado.</p>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead><tr><th>Seller</th><th>Mauzo</th><th>Mapato</th></tr></thead>
                                <tbody>
                                    <?php foreach (array_slice($sellerPerf, 0, 5) as $i => $s): ?>
                                    <tr>
                                        <td>
                                            <?php if ($i === 0 && $s['total_revenue'] > 0): ?><span style="color: var(--color-secondary); font-weight: 600;">#1</span>
                                            <?php elseif ($i === 1): ?><span style="color: var(--text-tertiary);">#2</span>
                                            <?php elseif ($i === 2): ?><span style="color: var(--text-tertiary);">#3</span>
                                            <?php else: ?><span style="color: var(--text-tertiary);">#<?php echo $i + 1; ?></span><?php endif; ?>
                                            <strong><?php echo htmlspecialchars($s['username']); ?></strong>
                                        </td>
                                        <td><?php echo number_format($s['sale_count']); ?></td>
                                        <td style="font-weight: 600; color: var(--color-secondary);"><?php echo number_format($s['total_revenue']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Package Popularity & Recent Sales -->
            <div class="grid-2col">
                <div class="admin-card">
                    <div class="admin-card-header">
                        <div class="admin-card-header-text">
                            <h2 class="admin-card-title">Packages Maarufu</h2>
                            <p class="admin-card-subtitle">Voucher zilizotengenezwa kwa package</p>
                        </div>
                        <a href="/admin/packages.php" class="btn btn-ghost btn-tiny">Simamia</a>
                    </div>
                    <?php if (empty($pkgPopularity)): ?>
                        <p style="text-align: center; color: var(--text-tertiary); padding: var(--space-6);">Hakuna data bado.</p>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead><tr><th>Package</th><th>Voucher</th><th>Hai</th><th>Zimekwisha</th></tr></thead>
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
                            <h2 class="admin-card-title">Mauzo ya Hivi Karibuni</h2>
                            <p class="admin-card-subtitle">Mauzo 5 ya mwisho</p>
                        </div>
                        <a href="/admin/analytics.php" class="btn btn-ghost btn-tiny">Ona Yote</a>
                    </div>
                    <?php if (empty($recentSales)): ?>
                        <p style="text-align: center; color: var(--text-tertiary); padding: var(--space-6);">Hakuna mauzo bado.</p>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead><tr><th>Seller</th><th>Voucher</th><th>Bei</th></tr></thead>
                                <tbody>
                                    <?php foreach ($recentSales as $sale): ?>
                                    <tr>
                                        <td style="font-weight: 500;"><?php echo htmlspecialchars($sale['seller_username']); ?></td>
                                        <td class="code-cell"><?php echo htmlspecialchars($sale['voucher_code']); ?></td>
                                        <td style="font-weight: 600;"><?php echo number_format($sale['price']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Voucher List -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-text">
                        <h2 class="admin-card-title">Orodha ya Voucher</h2>
                        <p class="admin-card-subtitle">Voucher zote kwenye mfumo</p>
                    </div>
                </div>

                <form method="GET" action="" class="filters-bar">
                    <select name="status" class="filter-select">
                        <option value="">Hali Zote</option>
                        <option value="unused" <?php echo $statusFilter === 'unused' ? 'selected' : ''; ?>>Hazijatumika</option>
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Zinafanya Kazi</option>
                        <option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>>Zimekwisha</option>
                    </select>
                    <select name="seller_id" class="filter-select">
                        <option value="">Seller Wote</option>
                        <?php foreach ($sellerList as $seller): ?>
                            <option value="<?php echo $seller['id']; ?>" <?php echo $sellerFilter === $seller['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($seller['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="search" class="filter-input" placeholder="Tafuta msimbo..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-secondary btn-small">Tafuta</button>
                    <?php if ($statusFilter || $search || $sellerFilter): ?><a href="/admin/dashboard.php" class="btn btn-secondary btn-small" style="text-decoration: none;">Futa</a><?php endif; ?>
                </form>

                <div class="table-wrapper">
                    <table class="data-table">
                        <thead><tr><th>Msimbo</th><th>Package</th><th>Hali</th><th>Imetengenezwa</th><th>Ilianza</th><th>Inakwisha</th><th>Seller</th><th>Hatua</th></tr></thead>
                        <tbody>
                            <?php if (empty($vouchers)): ?>
                                <tr><td colspan="8" style="text-align: center; color: var(--text-tertiary); padding: var(--space-8);">Hakuna voucher</td></tr>
                            <?php else: foreach ($vouchers as $v): ?>
                            <tr>
                                <td class="code-cell"><?php echo htmlspecialchars($v['code']); ?></td>
                                <td><?php echo htmlspecialchars($v['plan_name']); ?></td>
                                <td><span class="badge badge-<?php echo $v['status']; ?>"><?php echo $v['status']==='unused'?'Haijatumika':($v['status']==='active'?'Inafanya Kazi':'Imekwisha'); ?></span></td>
                                <td style="font-size: var(--text-sm);"><?php echo date('d/m H:i', strtotime($v['created_at'])); ?></td>
                                <td style="font-size: var(--text-sm);"><?php echo $v['first_used_at'] ? date('d/m H:i', strtotime($v['first_used_at'])) : '—'; ?></td>
                                <td style="font-size: var(--text-sm);"><?php echo $v['expires_at'] ? date('d/m H:i', strtotime($v['expires_at'])) : '—'; ?></td>
                                <td style="font-size: var(--text-sm);">
                                    <?php if (!empty($v['seller_id'])) { foreach ($sellerList as $s) { if ($s['id'] == $v['seller_id']) { echo '<strong>' . htmlspecialchars($s['username']) . '</strong>'; break; } } } else { echo htmlspecialchars($v['created_by'] ?? '—'); } ?>
                                </td>
                                <td>
                                    <?php if ($v['status'] === 'active'): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Maliza voucher hii?');"><input type="hidden" name="action" value="expire"><input type="hidden" name="code" value="<?php echo htmlspecialchars($v['code']); ?>"><button type="submit" class="btn btn-tiny btn-danger">Maliza</button></form>
                                    <?php else: ?><span style="color: var(--text-tertiary);">—</span><?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php $totalResults = count(getVouchers($statusFilter, $search, $sellerFilter, 10000, 0)); $totalPages = ceil($totalResults / $perPage); if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?><a href="?page=<?php echo $page-1; ?>&status=<?php echo urlencode($statusFilter); ?>&search=<?php echo urlencode($search); ?>&seller_id=<?php echo urlencode($sellerFilter ?? ''); ?>">Nyuma</a><?php endif; ?>
                    <?php for ($i = max(1, $page-2); $i <= min($totalPages, $page+2); $i++): ?><a href="?page=<?php echo $i; ?>&status=<?php echo urlencode($statusFilter); ?>&search=<?php echo urlencode($search); ?>&seller_id=<?php echo urlencode($sellerFilter ?? ''); ?>" class="<?php echo $i===$page?'active':''; ?>"><?php echo $i; ?></a><?php endfor; ?>
                    <?php if ($page < $totalPages): ?><a href="?page=<?php echo $page+1; ?>&status=<?php echo urlencode($statusFilter); ?>&search=<?php echo urlencode($search); ?>&seller_id=<?php echo urlencode($sellerFilter ?? ''); ?>">Mbele</a><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
<?php require dirname(__DIR__, 2) . '/src/admin_footer.php'; ?>
</body>
</html>
