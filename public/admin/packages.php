<?php
require_once dirname(__DIR__, 2) . '/src/auth.php';
require_once dirname(__DIR__, 2) . '/src/package_service.php';
startAppSession();
requireAdmin();

$adminUserId = getCurrentUserId() ?: null;
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } else {
        switch ($action) {
            case 'create':
                try {
                    $id = createPackage(
                        $_POST['name'] ?? '',
                        $_POST['slug'] ?? '',
                        intval($_POST['duration_seconds'] ?? 0),
                        floatval($_POST['price'] ?? 0),
                        !empty($_POST['bandwidth_mbps']) ? intval($_POST['bandwidth_mbps']) : null,
                        !empty($_POST['data_quota_mb']) ? intval($_POST['data_quota_mb']) : null,
                        trim($_POST['description'] ?? '') ?: null,
                        $adminUserId
                    );
                    $message = "Package created.";
                } catch (Exception $e) { $error = $e->getMessage(); }
                break;

            case 'update':
                $pkgId = intval($_POST['package_id'] ?? 0);
                if ($pkgId > 0) {
                    try {
                        $data = [
                            'name'             => $_POST['name'] ?? '',
                            'duration_seconds' => intval($_POST['duration_seconds'] ?? 0),
                            'price'            => floatval($_POST['price'] ?? 0),
                            'bandwidth_mbps'   => !empty($_POST['bandwidth_mbps']) ? intval($_POST['bandwidth_mbps']) : null,
                            'data_quota_mb'    => !empty($_POST['data_quota_mb']) ? intval($_POST['data_quota_mb']) : null,
                            'description'      => trim($_POST['description'] ?? '') ?: null,
                        ];
                        updatePackage($pkgId, $data, $adminUserId);
                        $message = "Package updated.";
                    } catch (Exception $e) { $error = $e->getMessage(); }
                }
                break;

            case 'activate':
                if (($pid = intval($_POST['package_id'] ?? 0)) > 0) {
                    activatePackage($pid, $adminUserId);
                    $message = 'Package activated.';
                }
                break;

            case 'deactivate':
                if (($pid = intval($_POST['package_id'] ?? 0)) > 0) {
                    deactivatePackage($pid, $adminUserId);
                    $message = 'Package deactivated.';
                }
                break;

            case 'delete':
                if (($pid = intval($_POST['package_id'] ?? 0)) > 0) {
                    try {
                        deletePackage($pid, $adminUserId);
                        $message = 'Package deleted (soft delete).';
                    } catch (Exception $e) { $error = $e->getMessage(); }
                }
                break;

            case 'restore':
                if (($pid = intval($_POST['package_id'] ?? 0)) > 0) {
                    restorePackage($pid, $adminUserId);
                    $message = 'Package restored.';
                }
                break;
        }
    }
}

$showDeleted = isset($_GET['show_deleted']);
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;
$packages = getAllPackages(null, $perPage, $offset, $showDeleted);
$totalPackages = countPackages(null, $showDeleted);
$totalPages = ceil($totalPackages / $perPage);
$pkgStats = getPackageStats();
$csrf = generateCSRFToken();

function formatDuration(int $seconds): string {
    if ($seconds < 3600) return round($seconds / 60) . ' min';
    if ($seconds < 86400) return round($seconds / 3600) . ' hr';
    return round($seconds / 86400) . ' day(s)';
}
$activePage = 'packages';
$pageTitle = 'Packages';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Packages - Admin</title>
    <?php require dirname(__DIR__, 2) . '/src/theme_init.php'; ?>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php require dirname(__DIR__, 2) . '/src/admin_header.php'; ?>
            <div class="section-header">
                <h1>Package Management</h1>
                <p>Manage the WiFi packages that sellers will use to generate vouchers.</p>
            </div>

            <?php if ($message): ?><div class="alert alert-success"><span><?php echo htmlspecialchars($message); ?></span></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon-wrap icon-secondary"><?php echo hi('PackageIcon', 20); ?></div>
                    </div>
                    <div class="stat-value"><?php echo $pkgStats['total'] ?? 0; ?></div>
                    <div class="stat-label">Total Packages</div>
                    <div class="stat-description">All packages in the system</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon-wrap icon-success"><?php echo hi('CheckmarkCircle01Icon', 20); ?></div>
                    </div>
                    <div class="stat-value"><?php echo $pkgStats['active'] ?? 0; ?></div>
                    <div class="stat-label">Active Packages</div>
                    <div class="stat-description">Available to sellers</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon-wrap icon-error"><?php echo hi('Alert02Icon', 20); ?></div>
                    </div>
                    <div class="stat-value"><?php echo $pkgStats['inactive'] ?? 0; ?></div>
                    <div class="stat-label">Deactivated Packages</div>
                    <div class="stat-description">Hidden from sellers</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon-wrap icon-primary"><?php echo hi('Delete02Icon', 20); ?></div>
                    </div>
                    <div class="stat-value"><?php echo $pkgStats['deleted'] ?? 0; ?></div>
                    <div class="stat-label">Deleted Packages</div>
                    <div class="stat-description">Soft deleted — data preserved</div>
                </div>
            </div>

            <!-- Package List -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-text">
                        <h2 class="admin-card-title">All Packages</h2>
                        <p class="admin-card-subtitle">List of all packages in the system</p>
                    </div>
                    <div style="display: flex; gap: var(--space-2);">
                        <?php if ($showDeleted): ?>
                            <a href="/admin/packages.php" class="btn btn-secondary btn-tiny">Hide Deleted</a>
                        <?php else: ?>
                            <a href="/admin/packages.php?show_deleted=1" class="btn btn-ghost btn-tiny">Show Deleted</a>
                        <?php endif; ?>
                        <button type="button" class="btn btn-primary btn-small" onclick="openCreateModal()"><?php echo hi('PackageIcon', 16); ?> Add Package</button>
                    </div>
                </div>
                <?php if (empty($packages)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><?php echo hi('PackageIcon', 44); ?></div>
                        <div class="empty-state-title">No packages yet</div>
                        <div class="empty-state-text">Create your first package using the button above.</div>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr><th>Name</th><th>Slug</th><th>Duration</th><th>Price (TZS)</th><th>Bandwidth</th><th>Quota</th><th>Status</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($packages as $pkg): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($pkg['name']); ?></strong>
                                        <?php if ($pkg['description']): ?><br><small style="color: var(--text-tertiary);"><?php echo htmlspecialchars($pkg['description']); ?></small><?php endif; ?>
                                    </td>
                                    <td class="code-cell"><?php echo htmlspecialchars($pkg['slug']); ?></td>
                                    <td><?php echo formatDuration((int)$pkg['duration_seconds']); ?></td>
                                    <td style="font-weight: 600; color: var(--color-secondary);"><?php echo number_format($pkg['price']); ?></td>
                                    <td><?php echo $pkg['bandwidth_mbps'] ? $pkg['bandwidth_mbps'] . ' Mbps' : '—'; ?></td>
                                    <td><?php echo $pkg['data_quota_mb'] ? number_format($pkg['data_quota_mb']) . ' MB' : '—'; ?></td>
                                    <td><span class="badge <?php echo !empty($pkg['is_deleted']) ? 'badge-expired' : ($pkg['is_active'] ? 'badge-active' : 'badge-expired'); ?>"><?php echo !empty($pkg['is_deleted']) ? 'Deleted' : ($pkg['is_active'] ? 'Active' : 'Inactive'); ?></span></td>
                                    <td>
                                        <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                            <?php if (!empty($pkg['is_deleted'])): ?>
                                                <form method="POST" style="display: inline;"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="package_id" value="<?php echo $pkg['id']; ?>"><button type="submit" class="btn btn-tiny btn-accent">Restore</button></form>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-tiny btn-secondary" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($pkg)); ?>)">Edit</button>
                                                <?php if ($pkg['is_active']): ?>
                                                    <form method="POST" style="display: inline;"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="package_id" value="<?php echo $pkg['id']; ?>"><button type="submit" class="btn btn-tiny btn-ghost">Deactivate</button></form>
                                                <?php else: ?>
                                                    <form method="POST" style="display: inline;"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="activate"><input type="hidden" name="package_id" value="<?php echo $pkg['id']; ?>"><button type="submit" class="btn btn-tiny btn-accent">Activate</button></form>
                                                <?php endif; ?>
                                                <form method="POST" style="display: inline;" data-confirm="Delete this package? Data will be preserved."><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="package_id" value="<?php echo $pkg['id']; ?>"><button type="submit" class="btn btn-tiny btn-danger">Delete</button></form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?><a href="?page=<?php echo $page-1; ?>&show_deleted=<?php echo $showDeleted ? 1 : 0; ?>">Back</a><?php endif; ?>
                        <?php for ($i = max(1, $page-2); $i <= min($totalPages, $page+2); $i++): ?><a href="?page=<?php echo $i; ?>&show_deleted=<?php echo $showDeleted ? 1 : 0; ?>" class="<?php echo $i===$page?'active':''; ?>"><?php echo $i; ?></a><?php endfor; ?>
                        <?php if ($page < $totalPages): ?><a href="?page=<?php echo $page+1; ?>&show_deleted=<?php echo $showDeleted ? 1 : 0; ?>">Next</a><?php endif; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
<?php require dirname(__DIR__, 2) . '/src/admin_footer.php'; ?>

    <!-- Create Modal -->
    <div id="createModal" class="modal-overlay">
        <div class="modal">
            <h3 class="modal-title">Add New Package</h3>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="create">
                <div class="form-row">
                    <div class="form-group">
                        <label for="name">Package Name *</label>
                        <input type="text" id="name" name="name" class="form-input" required placeholder="1 Day" oninput="autoSlug(this)">
                    </div>
                    <div class="form-group">
                        <label for="slug">Slug (URL) *</label>
                        <input type="text" id="slug" name="slug" class="form-input" required placeholder="1_day" pattern="[a-z0-9_]+">
                        <div class="form-hint">Lowercase letters, numbers, and _ only. Used as an identifier.</div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="duration_seconds">Duration (seconds) *</label>
                        <input type="number" id="duration_seconds" name="duration_seconds" class="form-input" required min="60" placeholder="86400">
                        <div class="form-hint">86400 = 1 day, 604800 = 1 week, 2592000 = 1 month</div>
                    </div>
                    <div class="form-group">
                        <label for="price">Price (TZS) *</label>
                        <input type="number" id="price" name="price" class="form-input" required min="0" step="50" placeholder="500">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="bandwidth_mbps">Bandwidth (Mbps)</label>
                        <input type="number" id="bandwidth_mbps" name="bandwidth_mbps" class="form-input" min="1" placeholder="10">
                        <div class="form-hint">Speed limit. Leave blank for unlimited.</div>
                    </div>
                    <div class="form-group">
                        <label for="data_quota_mb">Data Quota (MB)</label>
                        <input type="number" id="data_quota_mb" name="data_quota_mb" class="form-input" min="1" placeholder="1024">
                        <div class="form-hint">Data allowance. Leave blank for unlimited.</div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <input type="text" id="description" name="description" class="form-input" placeholder="Short description of this package">
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary btn-small" style="flex: 1;">Create Package</button>
                    <button type="button" class="btn btn-secondary btn-small" style="flex: 1;" onclick="closeCreateModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal-overlay">
        <div class="modal">
            <h3 class="modal-title">Edit Package</h3>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="package_id" id="edit_id" value="">
                <div class="form-group"><label>Name</label><input type="text" id="edit_name" name="name" class="form-input" required></div>
                <div class="form-row">
                    <div class="form-group"><label>Duration (seconds)</label><input type="number" id="edit_duration" name="duration_seconds" class="form-input" required min="60"></div>
                    <div class="form-group"><label>Price (TZS)</label><input type="number" id="edit_price" name="price" class="form-input" required min="0" step="50"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Bandwidth (Mbps)</label><input type="number" id="edit_bw" name="bandwidth_mbps" class="form-input" min="1"></div>
                    <div class="form-group"><label>Data Quota (MB)</label><input type="number" id="edit_quota" name="data_quota_mb" class="form-input" min="1"></div>
                </div>
                <div class="form-group"><label>Description</label><input type="text" id="edit_desc" name="description" class="form-input"></div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary btn-small" style="flex: 1;">Save</button>
                    <button type="button" class="btn btn-secondary btn-small" style="flex: 1;" onclick="closeEditModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openCreateModal() { document.getElementById('createModal').classList.add('open'); }
        function closeCreateModal() { document.getElementById('createModal').classList.remove('open'); }
        document.getElementById('createModal').addEventListener('click', function(e) { if (e.target === this) closeCreateModal(); });

        function autoSlug(nameInput) {
            var slug = nameInput.value.toLowerCase()
                .replace(/[^a-z0-9\s_]/g, '')
                .replace(/\s+/g, '_')
                .substring(0, 30);
            document.getElementById('slug').value = slug;
        }

        function openEditModal(pkg) {
            document.getElementById('edit_id').value = pkg.id;
            document.getElementById('edit_name').value = pkg.name;
            document.getElementById('edit_duration').value = pkg.duration_seconds;
            document.getElementById('edit_price').value = pkg.price;
            document.getElementById('edit_bw').value = pkg.bandwidth_mbps || '';
            document.getElementById('edit_quota').value = pkg.data_quota_mb || '';
            document.getElementById('edit_desc').value = pkg.description || '';
            document.getElementById('editModal').classList.add('open');
        }
        function closeEditModal() { document.getElementById('editModal').classList.remove('open'); }
        document.getElementById('editModal').addEventListener('click', function(e) { if (e.target === this) closeEditModal(); });
    </script>
</body>
</html>
