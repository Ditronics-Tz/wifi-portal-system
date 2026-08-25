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
        $error = 'Invalid request. Please try again.';
    } else {
        switch ($action) {
            case 'create':
                try {
                    $newId = createSeller(trim($_POST['username'] ?? ''), $_POST['password'] ?? '', trim($_POST['full_name'] ?? '') ?: null, trim($_POST['phone'] ?? '') ?: null, $adminUserId);
                    $message = "New staff created: " . htmlspecialchars(trim($_POST['username']));
                } catch (Exception $e) { $error = $e->getMessage(); }
                break;
            case 'activate':
                if (($sid = intval($_POST['seller_id'] ?? 0)) > 0) { activateSeller($sid, $adminUserId) ? $message = 'Staff activated.' : $error = 'Staff not found.'; }
                break;
            case 'deactivate':
                if (($sid = intval($_POST['seller_id'] ?? 0)) > 0) { deactivateSeller($sid, $adminUserId) ? $message = 'Staff deactivated.' : $error = 'Staff not found.'; }
                break;
            case 'delete':
                if (($sid = intval($_POST['seller_id'] ?? 0)) > 0) { deleteSeller($sid, $adminUserId) ? $message = 'Staff deleted.' : $error = 'Staff not found.'; }
                break;
            case 'change_password':
                if (($sid = intval($_POST['seller_id'] ?? 0)) > 0 && !empty($_POST['new_password'])) {
                    try { changeSellerPassword($sid, $_POST['new_password'], $adminUserId) ? $message = 'Password changed.' : $error = 'Staff not found.'; }
                    catch (Exception $e) { $error = $e->getMessage(); }
                }
                break;
        }
    }
}

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;
$activeFilter = $status === 'active' ? true : ($status === 'inactive' ? false : null);

$sellers = getSellers($search ?: null, $activeFilter, $perPage, $offset);
$totalSellers = countSellers($search ?: null, $activeFilter);
$totalPages = ceil($totalSellers / $perPage);
$summaryStats = getSellerSummaryStats();

// Admin accounts are shown pinned at the top of the list (page 1 only) — they
// are a separate role from staff and are not affected by staff actions below.
$adminAccounts = [];
if ($page === 1) {
    $db = getDB();
    $stmt = $db->query("
        SELECT u.*,
               (SELECT COUNT(*) FROM vouchers v WHERE v.seller_id = u.id) AS total_vouchers_generated,
               (SELECT COUNT(*) FROM sales s WHERE s.seller_id = u.id) AS total_sales
        FROM users u
        WHERE u.role = 'admin' AND u.is_deleted = false
        ORDER BY u.created_at ASC
    ");
    $adminAccounts = $stmt->fetchAll();
}

$csrf = generateCSRFToken();
$activePage = 'sellers';
$pageTitle = 'Staff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff - Admin</title>
    <?php require dirname(__DIR__, 2) . '/src/theme_init.php'; ?>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php require dirname(__DIR__, 2) . '/src/admin_header.php'; ?>
            <?php if ($message): ?><div class="alert alert-success"><span><?php echo htmlspecialchars($message); ?></span></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card"><div class="stat-value"><?php echo $summaryStats['total_sellers'] ?? 0; ?></div><div class="stat-label">Total Staff</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo $summaryStats['active_sellers'] ?? 0; ?></div><div class="stat-label">Active Staff</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo $summaryStats['inactive_sellers'] ?? 0; ?></div><div class="stat-label">Inactive Staff</div></div>
            </div>

            <!-- Staff List -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h2 class="admin-card-title">Staff List</h2>
                    <button type="button" class="btn btn-primary btn-small" onclick="openCreateModal()"><?php echo hi('UserGroupIcon', 16); ?> Add Staff</button>
                </div>
                <form method="GET" action="" class="filters-bar">
                    <input type="text" name="search" class="filter-input" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                    <select name="status" class="filter-select">
                        <option value="">All Statuses</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                    <button type="submit" class="btn btn-secondary btn-small">Search</button>
                    <?php if ($search || $status): ?><a href="/admin/sellers.php" class="btn btn-secondary btn-small" style="text-decoration: none;">Clear</a><?php endif; ?>
                </form>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead><tr><th>Username</th><th>Full Name</th><th>Phone</th><th>Status</th><th>Vouchers</th><th>Sales</th><th>Created</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($adminAccounts as $admin): ?>
                            <tr>
                                <td style="font-weight: 500;"><?php echo htmlspecialchars($admin['username']); ?></td>
                                <td><?php echo htmlspecialchars($admin['full_name'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($admin['phone'] ?? '—'); ?></td>
                                <td><span class="badge badge-active">Administrator</span></td>
                                <td><?php echo number_format($admin['total_vouchers_generated'] ?? 0); ?></td>
                                <td><?php echo number_format($admin['total_sales'] ?? 0); ?></td>
                                <td style="font-size: var(--text-sm);"><?php echo date('d/m/Y', strtotime($admin['created_at'])); ?></td>
                                <td><span style="color: var(--text-tertiary);">—</span></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($sellers) && empty($adminAccounts)): ?><tr><td colspan="8" style="text-align: center; color: var(--text-tertiary); padding: var(--space-8);">No staff</td></tr>
                            <?php else: foreach ($sellers as $seller): ?>
                            <tr>
                                <td style="font-weight: 500;"><?php echo htmlspecialchars($seller['username']); ?></td>
                                <td><?php echo htmlspecialchars($seller['full_name'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($seller['phone'] ?? '—'); ?></td>
                                <td><span class="badge <?php echo $seller['is_active'] ? 'badge-active' : 'badge-expired'; ?>"><?php echo $seller['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                <td><?php echo number_format($seller['total_vouchers_generated'] ?? 0); ?></td>
                                <td><?php echo number_format($seller['total_sales'] ?? 0); ?></td>
                                <td style="font-size: var(--text-sm);"><?php echo date('d/m/Y', strtotime($seller['created_at'])); ?></td>
                                <td>
                                    <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                        <?php if ($seller['is_active']): ?>
                                            <form method="POST" style="display: inline;" data-confirm="Deactivate this staff member?"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="seller_id" value="<?php echo $seller['id']; ?>"><button type="submit" class="btn btn-tiny btn-danger">Deactivate</button></form>
                                        <?php else: ?>
                                            <form method="POST" style="display: inline;"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="activate"><input type="hidden" name="seller_id" value="<?php echo $seller['id']; ?>"><button type="submit" class="btn btn-tiny btn-secondary">Activate</button></form>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-tiny btn-secondary" onclick="showChangePassword(<?php echo $seller['id']; ?>, '<?php echo htmlspecialchars($seller['username']); ?>')">Password</button>
                                        <form method="POST" style="display: inline;" data-confirm="Delete this staff member? This cannot be undone."><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="seller_id" value="<?php echo $seller['id']; ?>"><button type="submit" class="btn btn-tiny btn-danger">Delete</button></form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?><a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>">Back</a><?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?><a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>">Next</a><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
<?php require dirname(__DIR__, 2) . '/src/admin_footer.php'; ?>

    <!-- Create Staff Modal -->
    <div id="createModal" class="modal-overlay">
        <div class="modal">
            <h3 class="modal-title">Add New Staff</h3>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="create">
                <div class="grid-2col">
                    <div class="form-group"><label for="username">Username *</label><input type="text" id="username" name="username" class="form-input" required placeholder="staff_john" pattern="[a-zA-Z0-9_]{3,64}"></div>
                    <div class="form-group"><label for="password">Password *</label><input type="password" id="password" name="password" class="form-input" required minlength="8" placeholder="At least 8 characters"></div>
                    <div class="form-group"><label for="full_name">Full Name</label><input type="text" id="full_name" name="full_name" class="form-input" placeholder="Staff member's full name"></div>
                    <div class="form-group"><label for="phone">Phone Number</label><input type="text" id="phone" name="phone" class="form-input" placeholder="0712345678"></div>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary btn-small" style="flex: 1;">Create Staff</button>
                    <button type="button" class="btn btn-secondary btn-small" style="flex: 1;" onclick="closeCreateModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div id="passwordModal" class="modal-overlay">
        <div class="modal">
            <h3 class="modal-title">Change Password — <span id="modalSellerName"></span></h3>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="seller_id" id="modalSellerId" value="">
                <div class="form-group"><label for="new_password">New Password</label><input type="password" id="new_password" name="new_password" class="form-input" required minlength="8" placeholder="At least 8 characters"></div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary btn-small" style="flex: 1;">Change Password</button>
                    <button type="button" class="btn btn-secondary btn-small" style="flex: 1;" onclick="closePasswordModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function openCreateModal() { document.getElementById('createModal').classList.add('open'); }
        function closeCreateModal() { document.getElementById('createModal').classList.remove('open'); }
        document.getElementById('createModal').addEventListener('click', function(e) { if (e.target === this) closeCreateModal(); });

        function showChangePassword(id, name) { document.getElementById('modalSellerId').value = id; document.getElementById('modalSellerName').textContent = name; document.getElementById('passwordModal').classList.add('open'); }
        function closePasswordModal() { document.getElementById('passwordModal').classList.remove('open'); }
        document.getElementById('passwordModal').addEventListener('click', function(e) { if (e.target === this) closePasswordModal(); });
    </script>
</body>
</html>
