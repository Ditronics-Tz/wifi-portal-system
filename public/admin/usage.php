<?php
require_once dirname(__DIR__, 2) . '/src/auth.php';
require_once dirname(__DIR__, 2) . '/src/quota_service.php';
require_once dirname(__DIR__, 2) . '/src/package_service.php';
require_once dirname(__DIR__, 2) . '/src/voucher_service.php';
startAppSession();
requireAdmin();

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$planFilter = $_GET['plan_name'] ?? '';
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$usageStats = getSoldVoucherUsageStats(
    $dateFrom ?: null,
    $dateTo ?: null,
    $planFilter ?: null,
    $statusFilter ?: null,
    $search ?: null
);
$rows = getSoldVoucherUsage(
    $dateFrom ?: null,
    $dateTo ?: null,
    $planFilter ?: null,
    $statusFilter ?: null,
    $search ?: null,
    $perPage,
    $offset
);
$totalResults = countSoldVoucherUsage(
    $dateFrom ?: null,
    $dateTo ?: null,
    $planFilter ?: null,
    $statusFilter ?: null,
    $search ?: null
);
$totalPages = max(1, (int) ceil($totalResults / $perPage));
$packages = getAllPackages();

$activePage = 'usage';
$pageTitle = 'Data Usage';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Usage - Admin</title>
    <?php require dirname(__DIR__, 2) . '/src/theme_init.php'; ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('/assets/style.css')); ?>">
</head>
<body>
<?php require dirname(__DIR__, 2) . '/src/admin_header.php'; ?>
            <div class="section-header">
                <h1>Data usage</h1>
                <p>Track how much data each sold voucher has consumed (upload + download from RADIUS accounting).</p>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon-wrap icon-secondary"><?php echo hi('ChartLineData01Icon', 20); ?></div>
                    </div>
                    <div class="stat-value"><?php echo number_format($usageStats['total_mb'], 2); ?></div>
                    <div class="stat-label">Total data used (MB)</div>
                    <div class="stat-description">Across filtered sold vouchers</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon-wrap icon-success"><?php echo hi('Ticket01Icon', 20); ?></div>
                    </div>
                    <div class="stat-value"><?php echo number_format($usageStats['sold_count']); ?></div>
                    <div class="stat-label">Sold vouchers</div>
                    <div class="stat-description"><?php echo number_format($usageStats['used_count']); ?> with recorded usage</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon-wrap icon-primary"><?php echo hi('Wifi01Icon', 20); ?></div>
                    </div>
                    <div class="stat-value"><?php echo number_format($usageStats['active_count']); ?></div>
                    <div class="stat-label">Currently active</div>
                    <div class="stat-description">Sold vouchers in use right now</div>
                </div>
            </div>

            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-text">
                        <h2 class="admin-card-title">Voucher usage</h2>
                        <p class="admin-card-subtitle">Per-customer data consumption for sold vouchers</p>
                    </div>
                </div>

                <form method="GET" action="" class="filters-bar">
                    <input type="text" name="search" class="filter-input" placeholder="Search code, buyer..." value="<?php echo htmlspecialchars($search); ?>">
                    <select name="status" class="filter-select">
                        <option value="">All statuses</option>
                        <option value="unused" <?php echo $statusFilter === 'unused' ? 'selected' : ''; ?>>Unused</option>
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                    </select>
                    <select name="plan_name" class="filter-select">
                        <option value="">All packages</option>
                        <?php foreach ($packages as $pkg): ?>
                            <option value="<?php echo htmlspecialchars($pkg['name']); ?>" <?php echo $planFilter === $pkg['name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($pkg['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="date_from" class="filter-input" style="min-width: 140px;" value="<?php echo htmlspecialchars($dateFrom); ?>">
                    <input type="date" name="date_to" class="filter-input" style="min-width: 140px;" value="<?php echo htmlspecialchars($dateTo); ?>">
                    <button type="submit" class="btn btn-secondary btn-small">Search</button>
                    <?php if ($dateFrom || $dateTo || $statusFilter || $planFilter || $search): ?>
                        <a href="/admin/usage.php" class="btn btn-secondary btn-small" style="text-decoration: none;">Clear</a>
                    <?php endif; ?>
                </form>

                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Voucher</th>
                                <th>Package</th>
                                <th>Buyer</th>
                                <th>Status</th>
                                <th>Data used</th>
                                <th>Sold</th>
                                <th>First use</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: var(--text-tertiary); padding: var(--space-8);">
                                        No sold vouchers match your filters.
                                    </td>
                                </tr>
                            <?php else: foreach ($rows as $row): ?>
                                <?php
                                    $status = $row['voucher_status'] ?? 'unused';
                                    $statusLabel = ucfirst($status);
                                    $pct = $row['percent_used'];
                                    $barClass = 'progress-fill';
                                    if ($pct !== null) {
                                        if ($pct >= 90) {
                                            $barClass .= ' danger';
                                        } elseif ($pct >= 70) {
                                            $barClass .= ' warning';
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><?php echo renderVoucherCode($row['voucher_code'], true); ?></td>
                                    <td><?php echo htmlspecialchars($row['plan_name']); ?></td>
                                    <td>
                                        <?php if (!empty($row['buyer_name'])): ?>
                                            <div><?php echo htmlspecialchars($row['buyer_name']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($row['buyer_phone'])): ?>
                                            <div style="font-size: var(--text-sm); color: var(--text-tertiary);"><?php echo htmlspecialchars($row['buyer_phone']); ?></div>
                                        <?php endif; ?>
                                        <?php if (empty($row['buyer_name']) && empty($row['buyer_phone'])): ?>—<?php endif; ?>
                                    </td>
                                    <td><span class="badge badge-<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                                    <td style="min-width: 180px;">
                                        <?php if ($row['has_quota']): ?>
                                            <div style="font-size: var(--text-sm); font-weight: 600; margin-bottom: 4px;">
                                                <?php echo number_format($row['used_mb'], 2); ?> / <?php echo number_format($row['quota_mb']); ?> MB
                                            </div>
                                            <div class="progress-track" style="height: 6px;">
                                                <div class="<?php echo $barClass; ?>" style="width: <?php echo (float) $pct; ?>%;"></div>
                                            </div>
                                            <div style="font-size: var(--text-xs); color: var(--text-tertiary); margin-top: 4px;">
                                                <?php echo number_format((float) $pct, 1); ?>% used
                                                <?php if ($row['remaining_mb'] !== null): ?>
                                                    · <?php echo number_format($row['remaining_mb'], 2); ?> MB left
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif ($row['used_bytes'] > 0): ?>
                                            <div style="font-weight: 600;"><?php echo number_format($row['used_mb'], 2); ?> MB</div>
                                            <div style="font-size: var(--text-xs); color: var(--text-tertiary);">No package quota set</div>
                                        <?php else: ?>
                                            <span style="color: var(--text-tertiary);">0 MB</span>
                                            <?php if ($status === 'unused'): ?>
                                                <div style="font-size: var(--text-xs); color: var(--text-tertiary);">Not used yet</div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size: var(--text-sm);"><?php echo date('d/m/Y H:i', strtotime($row['sold_at'])); ?></td>
                                    <td style="font-size: var(--text-sm);">
                                        <?php echo !empty($row['first_used_at']) ? date('d/m/Y H:i', strtotime($row['first_used_at'])) : '—'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="btn btn-secondary btn-small" style="text-decoration: none;">Previous</a>
                        <?php endif; ?>
                        <span style="color: var(--text-secondary); font-size: var(--text-sm);">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                        <?php if ($page < $totalPages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="btn btn-secondary btn-small" style="text-decoration: none;">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
<?php require dirname(__DIR__, 2) . '/src/admin_footer.php'; ?>
</body>
</html>
