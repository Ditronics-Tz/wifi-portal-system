<?php
/**
 * Admin dashboard - Voucher management
 */

require_once '/var/www/voucher-portal/src/auth.php';
require_once '/var/www/voucher-portal/src/voucher_service.php';

session_start();
requireAdmin();

// Get filters
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Handle force expire action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'expire' && isset($_POST['code'])) {
        $code = preg_replace('/[^A-Z0-9]/', '', $_POST['code']);
        if (!empty($code)) {
            forceExpireVoucher($code);
            $message = "Voucher $code imekwishwa muda kwa nguvu.";
        }
    }
}

// Get data
$vouchers = getVouchers($statusFilter, $search, $perPage, $offset);
$stats = countVouchersByStatus();
?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - WiFi Voucher Admin</title>
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
</head>
<body>
    <div class="admin-wrapper">
        <!-- Header -->
        <header class="admin-header">
            <div class="admin-header-inner">
                <a href="dashboard.php" class="admin-logo">
                    <div class="admin-logo-icon">📡</div>
                    <span class="admin-logo-text">Voucher Admin</span>
                </a>
                
                <nav class="admin-nav">
                    <a href="dashboard.php" class="active">Dashboard</a>
                    <a href="generate.php">Generate</a>
                </nav>
                
                <div class="admin-user">
                    <div class="admin-user-avatar"><?php echo strtoupper(substr($_SESSION['admin_username'] ?? 'A', 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                    <a href="logout.php" style="color: #94a3b8; text-decoration: none; font-size: 13px;">Toka →</a>
                </div>
            </div>
        </header>
        
        <!-- Content -->
        <main class="admin-content">
            <?php if (isset($message)): ?>
                <div class="alert alert-success" style="margin-bottom: 24px;">
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>
            
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon total">📊</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
                        <div class="stat-label">Jumla ya Voucher</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon unused">📦</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['unused'] ?? 0; ?></div>
                        <div class="stat-label">Hazijatumika</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon active">✅</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['active'] ?? 0; ?></div>
                        <div class="stat-label">Zinafanya Kazi</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon expired">⏰</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['expired'] ?? 0; ?></div>
                        <div class="stat-label">Zimekwisha</div>
                    </div>
                </div>
            </div>
            
            <!-- Voucher List -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h2 class="admin-card-title">Orodha ya Voucher</h2>
                </div>
                
                <!-- Filters -->
                <form method="GET" action="" class="filters-bar">
                    <select name="status" class="filter-select">
                        <option value="">Hali Zote</option>
                        <option value="unused" <?php echo $statusFilter === 'unused' ? 'selected' : ''; ?>>Hazijatumika</option>
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Zinafanya Kazi</option>
                        <option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>>Zimekwisha</option>
                    </select>
                    
                    <input type="text" name="search" class="filter-input" placeholder="Tafuta kwa msimbo..." value="<?php echo htmlspecialchars($search); ?>">
                    
                    <button type="submit" class="btn btn-secondary btn-small">Tafuta</button>
                    
                    <?php if ($statusFilter || $search): ?>
                        <a href="dashboard.php" class="btn btn-secondary btn-small" style="text-decoration: none;">Futa Filter</a>
                    <?php endif; ?>
                </form>
                
                <!-- Table -->
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Msimbo</th>
                                <th>Mpango</th>
                                <th>Hali</th>
                                <th>Imetengenezwa</th>
                                <th>Imetumika Mara ya Kwanza</th>
                                <th>Inakwisha</th>
                                <th>Kwa</th>
                                <th>Hatua</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($vouchers)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; color: #94a3b8; padding: 40px;">
                                        Hakuna voucher zilizopatikana
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($vouchers as $v): ?>
                                    <tr>
                                        <td class="code-cell">
                                            <?php echo htmlspecialchars($v['code']); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($v['plan_name']); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $v['status']; ?>">
                                                <?php 
                                                if ($v['status'] === 'unused') echo 'Haijatumika';
                                                elseif ($v['status'] === 'active') echo 'Inafanya Kazi';
                                                else echo 'Imekwisha';
                                                ?>
                                            </span>
                                        </td>
                                        <td style="font-size: 13px;"><?php echo date('d/m H:i', strtotime($v['created_at'])); ?></td>
                                        <td style="font-size: 13px;"><?php echo $v['first_used_at'] ? date('d/m H:i', strtotime($v['first_used_at'])) : '—'; ?></td>
                                        <td style="font-size: 13px;"><?php echo $v['expires_at'] ? date('d/m H:i', strtotime($v['expires_at'])) : '—'; ?></td>
                                        <td style="font-size: 13px;"><?php echo htmlspecialchars($v['created_by'] ?? '—'); ?></td>
                                        <td>
                                            <?php if ($v['status'] === 'active'): ?>
                                                <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Una uhakika unataka kumaliza voucher hii?');">
                                                    <input type="hidden" name="action" value="expire">
                                                    <input type="hidden" name="code" value="<?php echo htmlspecialchars($v['code']); ?>">
                                                    <button type="submit" class="btn btn-tiny btn-danger">Maliza</button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: #94a3b8;">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php
                $totalResults = count(getVouchers($statusFilter, $search, 10000, 0));
                $totalPages = ceil($totalResults / $perPage);
                if ($totalPages > 1):
                ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&status=<?php echo urlencode($statusFilter); ?>&search=<?php echo urlencode($search); ?>">← Nyuma</a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <a href="?page=<?php echo $i; ?>&status=<?php echo urlencode($statusFilter); ?>&search=<?php echo urlencode($search); ?>" class="<?php echo $i === $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&status=<?php echo urlencode($statusFilter); ?>&search=<?php echo urlencode($search); ?>">Mbele →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
