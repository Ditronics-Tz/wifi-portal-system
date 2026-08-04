<?php
/**
 * Admin dashboard — Voucher management + RADIUS test button
 */

require_once dirname(__DIR__) . '/src/auth.php';
require_once dirname(__DIR__) . '/src/voucher_service.php';
require_once dirname(__DIR__) . '/src/radius_client.php';

session_start();
requireAdmin();

// Get filters
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$search       = isset($_GET['search']) ? trim($_GET['search']) : '';
$page         = isset($_GET['page'])   ? max(1, intval($_GET['page'])) : 1;
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

// Handle actions
$message   = null;
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Force expire
    if ($_POST['action'] === 'expire' && isset($_POST['code'])) {
        $code = preg_replace('/[^A-Z0-9]/', '', $_POST['code']);
        if (!empty($code)) {
            forceExpireVoucher($code);
            $message = "Voucher $code imekwishwa muda kwa nguvu.";
        }
    }

    // RADIUS test (radclient)
    if ($_POST['action'] === 'test_radius' && isset($_POST['code'])) {
        $code = preg_replace('/[^A-Z0-9]/', '', $_POST['code']);
        if (!empty($code)) {
            $testResult = radius_authenticate($code, $code);
        }
    }
}

// Get data
$vouchers = getVouchers($statusFilter, $search, $perPage, $offset);
$stats    = countVouchersByStatus();
?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - WiFi Voucher Admin</title>
    <link rel="stylesheet" href="/assets/style.css">
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
                    <div class="admin-user-avatar"><?= strtoupper(substr($_SESSION['admin_username'] ?? 'A', 0, 1)) ?></div>
                    <span><?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?></span>
                    <a href="logout.php" style="color:#94a3b8; text-decoration:none; font-size:13px;">Toka →</a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="admin-content">

            <?php if ($message): ?>
                <div class="alert alert-success" style="margin-bottom:24px;">
                    <span><?= htmlspecialchars($message) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($testResult): ?>
                <div class="admin-card" style="margin-bottom:24px; border-left:4px solid <?= $testResult['success'] ? '#10b981' : '#ef4444' ?>;">
                    <div class="admin-card-header">
                        <h2 class="admin-card-title">
                            RADIUS Test: <?= htmlspecialchars($_POST['code'] ?? '') ?>
                            — <?= $testResult['success'] ? '✅ Access-Accept' : '❌ Access-Reject' ?>
                        </h2>
                    </div>
                    <p style="margin:8px 0; color:#475569;"><?= htmlspecialchars($testResult['message']) ?></p>
                    <?php if (!empty($testResult['attributes'])): ?>
                        <pre style="background:#f1f5f9; padding:12px; border-radius:8px; font-size:13px; overflow-x:auto;"><?php
                            foreach ($testResult['attributes'] as $k => $v) {
                                echo htmlspecialchars($k) . ' = ' . htmlspecialchars($v) . "\n";
                            }
                        ?></pre>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon total">📊</div>
                    <div class="stat-content">
                        <div class="stat-value"><?= $stats['total'] ?? 0 ?></div>
                        <div class="stat-label">Jumla ya Voucher</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon unused">📦</div>
                    <div class="stat-content">
                        <div class="stat-value"><?= $stats['unused'] ?? 0 ?></div>
                        <div class="stat-label">Hazijatumika</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon active">✅</div>
                    <div class="stat-content">
                        <div class="stat-value"><?= $stats['active'] ?? 0 ?></div>
                        <div class="stat-label">Zinafanya Kazi</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon expired">⏰</div>
                    <div class="stat-content">
                        <div class="stat-value"><?= $stats['expired'] ?? 0 ?></div>
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
                        <option value="unused"  <?= $statusFilter === 'unused'  ? 'selected' : '' ?>>Hazijatumika</option>
                        <option value="active"  <?= $statusFilter === 'active'  ? 'selected' : '' ?>>Zinafanya Kazi</option>
                        <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Zimekwisha</option>
                    </select>
                    <input type="text" name="search" class="filter-input" placeholder="Tafuta kwa msimbo..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn btn-secondary btn-small">Tafuta</button>
                    <?php if ($statusFilter || $search): ?>
                        <a href="dashboard.php" class="btn btn-secondary btn-small" style="text-decoration:none;">Futa Filter</a>
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
                                <th>Mara ya Kwanza</th>
                                <th>Inakwisha</th>
                                <th>Kwa</th>
                                <th>Hatua</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($vouchers)): ?>
                                <tr>
                                    <td colspan="8" style="text-align:center; color:#94a3b8; padding:40px;">
                                        Hakuna voucher zilizopatikana
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($vouchers as $v): ?>
                                    <tr>
                                        <td class="code-cell"><?= htmlspecialchars($v['code']) ?></td>
                                        <td><?= htmlspecialchars($v['plan_name']) ?></td>
                                        <td>
                                            <span class="badge badge-<?= $v['status'] ?>">
                                                <?= match($v['status']) {
                                                    'unused'  => 'Haijatumika',
                                                    'active'  => 'Inafanya Kazi',
                                                    'expired' => 'Imekwisha',
                                                    default   => $v['status'],
                                                } ?>
                                            </span>
                                        </td>
                                        <td style="font-size:13px;"><?= date('d/m H:i', strtotime($v['created_at'])) ?></td>
                                        <td style="font-size:13px;"><?= $v['first_used_at'] ? date('d/m H:i', strtotime($v['first_used_at'])) : '—' ?></td>
                                        <td style="font-size:13px;"><?= $v['expires_at'] ? date('d/m H:i', strtotime($v['expires_at'])) : '—' ?></td>
                                        <td style="font-size:13px;"><?= htmlspecialchars($v['created_by'] ?? '—') ?></td>
                                        <td style="white-space:nowrap;">
                                            <!-- Test Voucher (radclient) -->
                                            <form method="POST" action="" style="display:inline;">
                                                <input type="hidden" name="action" value="test_radius">
                                                <input type="hidden" name="code"   value="<?= htmlspecialchars($v['code']) ?>">
                                                <button type="submit" class="btn btn-tiny btn-secondary" title="Test Voucher dhidi ya RADIUS">🧪</button>
                                            </form>

                                            <?php if ($v['status'] === 'active'): ?>
                                            <!-- Force Expire -->
                                            <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Una uhakika unataka kumaliza voucher hii?');">
                                                <input type="hidden" name="action" value="expire">
                                                <input type="hidden" name="code"   value="<?= htmlspecialchars($v['code']) ?>">
                                                <button type="submit" class="btn btn-tiny btn-danger">Maliza</button>
                                            </form>
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
                $totalPages   = ceil($totalResults / $perPage);
                if ($totalPages > 1):
                ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($search) ?>">← Nyuma</a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <a href="?page=<?= $i ?>&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($search) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?= $page + 1 ?>&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($search) ?>">Mbele →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
