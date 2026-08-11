<?php
require_once dirname(__DIR__, 2) . '/src/auth.php';
require_once dirname(__DIR__, 2) . '/src/user_service.php';
startAppSession();
requireAdmin();

$adminUserId = getCurrentUserId() ?: null;
$adminUsername = getCurrentUsername();
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ombi si sahihi. Jaribu tena.';
    } else {
        switch ($action) {
            case 'create':
                try {
                    $newId = createSeller(trim($_POST['username'] ?? ''), $_POST['password'] ?? '', trim($_POST['full_name'] ?? '') ?: null, trim($_POST['phone'] ?? '') ?: null, $adminUserId);
                    $message = "Seller mpya ameundwa: " . htmlspecialchars(trim($_POST['username']));
                } catch (Exception $e) { $error = $e->getMessage(); }
                break;
            case 'activate':
                if (($sid = intval($_POST['seller_id'] ?? 0)) > 0) { activateSeller($sid, $adminUserId) ? $message = 'Seller amewashwa.' : $error = 'Seller hajapatikana.'; }
                break;
            case 'deactivate':
                if (($sid = intval($_POST['seller_id'] ?? 0)) > 0) { deactivateSeller($sid, $adminUserId) ? $message = 'Seller amezimwa.' : $error = 'Seller hajapatikana.'; }
                break;
            case 'delete':
                if (($sid = intval($_POST['seller_id'] ?? 0)) > 0) { deleteSeller($sid, $adminUserId) ? $message = 'Seller amefutwa.' : $error = 'Seller hajapatikana.'; }
                break;
            case 'change_password':
                if (($sid = intval($_POST['seller_id'] ?? 0)) > 0 && !empty($_POST['new_password'])) {
                    try { changeSellerPassword($sid, $_POST['new_password'], $adminUserId) ? $message = 'Nywila imebadilishwa.' : $error = 'Seller hajapatikana.'; }
                    catch (Exception $e) { $error = $e->getMessage(); }
                }
                break;
        }
    }
}

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$activeFilter = $status === 'active' ? true : ($status === 'inactive' ? false : null);

$sellers = getSellers($search ?: null, $activeFilter, $perPage, $offset);
$totalSellers = countSellers($search ?: null, $activeFilter);
$totalPages = ceil($totalSellers / $perPage);
$summaryStats = getSellerSummaryStats();
$csrf = generateCSRFToken();
$activePage = 'sellers';
$pageTitle = 'Sellers';
?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sellers - Admin</title>
    <?php require dirname(__DIR__, 2) . '/src/theme_init.php'; ?>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php require dirname(__DIR__, 2) . '/src/admin_header.php'; ?>
            <?php if ($message): ?><div class="alert alert-success"><span><?php echo htmlspecialchars($message); ?></span></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card"><div class="stat-value"><?php echo $summaryStats['total_sellers'] ?? 0; ?></div><div class="stat-label">Jumla ya Sellers</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo $summaryStats['active_sellers'] ?? 0; ?></div><div class="stat-label">Sellers Hai</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo $summaryStats['inactive_sellers'] ?? 0; ?></div><div class="stat-label">Waliopumzika</div></div>
            </div>

            <!-- Create Seller -->
            <div class="admin-card">
                <div class="admin-card-header"><h2 class="admin-card-title">Ongeza Seller Mpya</h2></div>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="grid-2col">
                        <div class="form-group"><label for="username">Jina la Mtumiaji *</label><input type="text" id="username" name="username" class="form-input" required placeholder="seller_john" pattern="[a-zA-Z0-9_]{3,64}"></div>
                        <div class="form-group"><label for="password">Nywila *</label><input type="password" id="password" name="password" class="form-input" required minlength="8" placeholder="Angalau herufi 8"></div>
                        <div class="form-group"><label for="full_name">Jina Kamili</label><input type="text" id="full_name" name="full_name" class="form-input" placeholder="Jina la seller"></div>
                        <div class="form-group"><label for="phone">Namba ya Simu</label><input type="text" id="phone" name="phone" class="form-input" placeholder="0712345678"></div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="max-width: 200px;">Unda Seller</button>
                </form>
            </div>

            <!-- Sellers List -->
            <div class="admin-card">
                <div class="admin-card-header"><h2 class="admin-card-title">Orodha ya Sellers</h2></div>
                <form method="GET" action="" class="filters-bar">
                    <select name="status" class="filter-select">
                        <option value="">Hali Zote</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Hai</option>
                        <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Waliopumzika</option>
                    </select>
                    <input type="text" name="search" class="filter-input" placeholder="Tafuta..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-secondary btn-small">Tafuta</button>
                    <?php if ($search || $status): ?><a href="/admin/sellers.php" class="btn btn-secondary btn-small" style="text-decoration: none;">Futa</a><?php endif; ?>
                </form>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead><tr><th>Jina</th><th>Jina Kamili</th><th>Simu</th><th>Hali</th><th>Voucher</th><th>Mauzo</th><th>Imeundwa</th><th>Hatua</th></tr></thead>
                        <tbody>
                            <?php if (empty($sellers)): ?><tr><td colspan="8" style="text-align: center; color: var(--text-tertiary); padding: var(--space-8);">Hakuna sellers</td></tr>
                            <?php else: foreach ($sellers as $seller): ?>
                            <tr>
                                <td style="font-weight: 500;"><?php echo htmlspecialchars($seller['username']); ?></td>
                                <td><?php echo htmlspecialchars($seller['full_name'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($seller['phone'] ?? '—'); ?></td>
                                <td><span class="badge <?php echo $seller['is_active'] ? 'badge-active' : 'badge-expired'; ?>"><?php echo $seller['is_active'] ? 'Hai' : 'Imezimwa'; ?></span></td>
                                <td><?php echo number_format($seller['total_vouchers_generated'] ?? 0); ?></td>
                                <td><?php echo number_format($seller['total_sales'] ?? 0); ?></td>
                                <td style="font-size: var(--text-sm);"><?php echo date('d/m/Y', strtotime($seller['created_at'])); ?></td>
                                <td>
                                    <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                        <?php if ($seller['is_active']): ?>
                                            <form method="POST" style="display: inline;"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="seller_id" value="<?php echo $seller['id']; ?>"><button type="submit" class="btn btn-tiny btn-danger" onclick="return confirm('Zima seller huyu?');">Zima</button></form>
                                        <?php else: ?>
                                            <form method="POST" style="display: inline;"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="activate"><input type="hidden" name="seller_id" value="<?php echo $seller['id']; ?>"><button type="submit" class="btn btn-tiny btn-secondary">Washa</button></form>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-tiny btn-secondary" onclick="showChangePassword(<?php echo $seller['id']; ?>, '<?php echo htmlspecialchars($seller['username']); ?>')">Nywila</button>
                                        <form method="POST" style="display: inline;"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="seller_id" value="<?php echo $seller['id']; ?>"><button type="submit" class="btn btn-tiny btn-danger" onclick="return confirm('Futa seller huyu?');">Futa</button></form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?><a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>">Nyuma</a><?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?><a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>">Mbele</a><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
<?php require dirname(__DIR__, 2) . '/src/admin_footer.php'; ?>

    <div id="passwordModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.4); z-index: 1000; align-items: center; justify-content: center; padding: var(--space-5);">
        <div style="background: var(--surface-raised); border-radius: var(--radius-lg); padding: var(--space-6); max-width: 400px; width: 100%; border: 1px solid var(--border-default);">
            <h3 style="font-size: var(--text-md); font-weight: 600; margin-bottom: var(--space-4);">Badilisha Nywila — <span id="modalSellerName"></span></h3>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="seller_id" id="modalSellerId" value="">
                <div class="form-group"><label for="new_password">Nywila Mpya</label><input type="password" id="new_password" name="new_password" class="form-input" required minlength="8" placeholder="Angalau herufi 8"></div>
                <div style="display: flex; gap: var(--space-2);">
                    <button type="submit" class="btn btn-primary btn-small" style="flex: 1;">Badilisha</button>
                    <button type="button" class="btn btn-secondary btn-small" style="flex: 1;" onclick="closePasswordModal()">Ghairi</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function showChangePassword(id, name) { document.getElementById('modalSellerId').value = id; document.getElementById('modalSellerName').textContent = name; document.getElementById('passwordModal').style.display = 'flex'; }
        function closePasswordModal() { document.getElementById('passwordModal').style.display = 'none'; }
        document.getElementById('passwordModal').addEventListener('click', function(e) { if (e.target === this) closePasswordModal(); });
    </script>
</body>
</html>
