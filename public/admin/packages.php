<?php
require_once dirname(__DIR__, 2) . '/src/auth.php';
require_once dirname(__DIR__, 2) . '/src/package_service.php';
startAppSession();
requireAdmin();

$adminUserId = getCurrentUserId() ?: null;
$adminUsername = getCurrentUsername();
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ombi si sahihi.';
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
                    $message = "Package imeundwa.";
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
                        $message = "Package imesasishwa.";
                    } catch (Exception $e) { $error = $e->getMessage(); }
                }
                break;

            case 'activate':
                if (($pid = intval($_POST['package_id'] ?? 0)) > 0) {
                    activatePackage($pid, $adminUserId);
                    $message = 'Package imewashwa.';
                }
                break;

            case 'deactivate':
                if (($pid = intval($_POST['package_id'] ?? 0)) > 0) {
                    deactivatePackage($pid, $adminUserId);
                    $message = 'Package imezimwa.';
                }
                break;

            case 'delete':
                if (($pid = intval($_POST['package_id'] ?? 0)) > 0) {
                    try {
                        deletePackage($pid, $adminUserId);
                        $message = 'Package imefutwa (soft delete).';
                    } catch (Exception $e) { $error = $e->getMessage(); }
                }
                break;

            case 'restore':
                if (($pid = intval($_POST['package_id'] ?? 0)) > 0) {
                    restorePackage($pid, $adminUserId);
                    $message = 'Package imerejeshwa.';
                }
                break;
        }
    }
}

$showDeleted = isset($_GET['show_deleted']);
$packages = getAllPackages(null, 100, 0, $showDeleted);
$pkgStats = getPackageStats();
$csrf = generateCSRFToken();

function formatDuration(int $seconds): string {
    if ($seconds < 3600) return round($seconds / 60) . ' dakika';
    if ($seconds < 86400) return round($seconds / 3600) . ' saa';
    return round($seconds / 86400) . ' siku';
}
?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Packages - Admin</title>
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
                    <a href="/admin/packages.php" class="active">Packages</a>
                    <a href="/admin/analytics.php">Analytics</a>
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
            <div class="section-header">
                <h1>Package Management</h1>
                <p>Simamia packages za WiFi ambazo sellers watazitumia kutengeneza voucher.</p>
            </div>

            <?php if ($message): ?><div class="alert alert-success"><span><?php echo htmlspecialchars($message); ?></span></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon-wrap icon-secondary">📦</div>
                    </div>
                    <div class="stat-value"><?php echo $pkgStats['total'] ?? 0; ?></div>
                    <div class="stat-label">Jumla ya Packages</div>
                    <div class="stat-description">Packages zote kwenye mfumo</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon-wrap icon-success">✓</div>
                    </div>
                    <div class="stat-value"><?php echo $pkgStats['active'] ?? 0; ?></div>
                    <div class="stat-label">Packages Hai</div>
                    <div class="stat-description">Zinazopatikana kwa sellers</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon-wrap icon-error">○</div>
                    </div>
                    <div class="stat-value"><?php echo $pkgStats['inactive'] ?? 0; ?></div>
                    <div class="stat-label">Packages Zilizozimwa</div>
                    <div class="stat-description">Hazionekani kwa sellers</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon-wrap icon-primary">🗑</div>
                    </div>
                    <div class="stat-value"><?php echo $pkgStats['deleted'] ?? 0; ?></div>
                    <div class="stat-label">Packages Zilizofutwa</div>
                    <div class="stat-description">Soft deleted — data imehifadhiwa</div>
                </div>
            </div>

            <!-- Create Package -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-text">
                        <h2 class="admin-card-title">Ongeza Package Mpya</h2>
                        <p class="admin-card-subtitle">Unda package mpya ya WiFi ambayo sellers wataitumia</p>
                    </div>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="name">Jina la Package *</label>
                            <input type="text" id="name" name="name" class="form-input" required placeholder="Siku 1" oninput="autoSlug(this)">
                        </div>
                        <div class="form-group">
                            <label for="slug">Slug (URL) *</label>
                            <input type="text" id="slug" name="slug" class="form-input" required placeholder="siku_1" pattern="[a-z0-9_]+">
                            <div class="form-hint">Herufi ndogo, nambari, na _ tu. Inatumika kama kitambulisho.</div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="duration_seconds">Muda (sekunde) *</label>
                            <input type="number" id="duration_seconds" name="duration_seconds" class="form-input" required min="60" placeholder="86400">
                            <div class="form-hint">86400 = siku 1, 604800 = wiki 1, 2592000 = mwezi 1</div>
                        </div>
                        <div class="form-group">
                            <label for="price">Bei (TZS) *</label>
                            <input type="number" id="price" name="price" class="form-input" required min="0" step="50" placeholder="500">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="bandwidth_mbps">Bandwidth (Mbps)</label>
                            <input type="number" id="bandwidth_mbps" name="bandwidth_mbps" class="form-input" min="1" placeholder="10">
                            <div class="form-hint">Kiasi cha kasi ya mtandao. Acha tupu kama hakuna kikomo.</div>
                        </div>
                        <div class="form-group">
                            <label for="data_quota_mb">Data Quota (MB)</label>
                            <input type="number" id="data_quota_mb" name="data_quota_mb" class="form-input" min="1" placeholder="1024">
                            <div class="form-hint">Kiasi cha data kinachoruhusiwa. Acha tupu kama hakuna kikomo.</div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="description">Maelezo</label>
                        <input type="text" id="description" name="description" class="form-input" placeholder="Maelezo mafupi kuhusu package hii">
                    </div>
                    <button type="submit" class="btn btn-accent" style="max-width: 200px;">Unda Package</button>
                </form>
                <script>
                    function autoSlug(nameInput) {
                        var slug = nameInput.value.toLowerCase()
                            .replace(/[^a-z0-9\s_]/g, '')
                            .replace(/\s+/g, '_')
                            .substring(0, 30);
                        document.getElementById('slug').value = slug;
                    }
                </script>
            </div>

            <!-- Package List -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-text">
                        <h2 class="admin-card-title">Packages Zote</h2>
                        <p class="admin-card-subtitle">Orodha ya packages zote kwenye mfumo</p>
                    </div>
                    <?php if ($showDeleted): ?>
                        <a href="/admin/packages.php" class="btn btn-secondary btn-tiny">Ficha Zilizofutwa</a>
                    <?php else: ?>
                        <a href="/admin/packages.php?show_deleted=1" class="btn btn-ghost btn-tiny">Ona Zilizofutwa</a>
                    <?php endif; ?>
                </div>
                <?php if (empty($packages)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📦</div>
                        <div class="empty-state-title">Hakuna packages bado</div>
                        <div class="empty-state-text">Unda package ya kwanza hapo juu.</div>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr><th>Jina</th><th>Slug</th><th>Muda</th><th>Bei (TZS)</th><th>Bandwidth</th><th>Quota</th><th>Hali</th><th>Hatua</th></tr>
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
                                    <td><span class="badge <?php echo !empty($pkg['is_deleted']) ? 'badge-expired' : ($pkg['is_active'] ? 'badge-active' : 'badge-expired'); ?>"><?php echo !empty($pkg['is_deleted']) ? 'Imefutwa' : ($pkg['is_active'] ? 'Hai' : 'Imezimwa'); ?></span></td>
                                    <td>
                                        <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                            <?php if (!empty($pkg['is_deleted'])): ?>
                                                <form method="POST" style="display: inline;"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="package_id" value="<?php echo $pkg['id']; ?>"><button type="submit" class="btn btn-tiny btn-accent">Rejesha</button></form>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-tiny btn-secondary" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($pkg)); ?>)">Hariri</button>
                                                <?php if ($pkg['is_active']): ?>
                                                    <form method="POST" style="display: inline;"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="package_id" value="<?php echo $pkg['id']; ?>"><button type="submit" class="btn btn-tiny btn-ghost">Zima</button></form>
                                                <?php else: ?>
                                                    <form method="POST" style="display: inline;"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="activate"><input type="hidden" name="package_id" value="<?php echo $pkg['id']; ?>"><button type="submit" class="btn btn-tiny btn-accent">Washa</button></form>
                                                <?php endif; ?>
                                                <form method="POST" style="display: inline;"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="package_id" value="<?php echo $pkg['id']; ?>"><button type="submit" class="btn btn-tiny btn-danger" onclick="return confirm('Futa package hii? Data itahifadhiwa.');">Futa</button></form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal-overlay">
        <div class="modal">
            <h3 class="modal-title">Hariri Package</h3>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="package_id" id="edit_id" value="">
                <div class="form-group"><label>Jina</label><input type="text" id="edit_name" name="name" class="form-input" required></div>
                <div class="form-row">
                    <div class="form-group"><label>Muda (sekunde)</label><input type="number" id="edit_duration" name="duration_seconds" class="form-input" required min="60"></div>
                    <div class="form-group"><label>Bei (TZS)</label><input type="number" id="edit_price" name="price" class="form-input" required min="0" step="50"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Bandwidth (Mbps)</label><input type="number" id="edit_bw" name="bandwidth_mbps" class="form-input" min="1"></div>
                    <div class="form-group"><label>Data Quota (MB)</label><input type="number" id="edit_quota" name="data_quota_mb" class="form-input" min="1"></div>
                </div>
                <div class="form-group"><label>Maelezo</label><input type="text" id="edit_desc" name="description" class="form-input"></div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary btn-small" style="flex: 1;">Hifadhi</button>
                    <button type="button" class="btn btn-secondary btn-small" style="flex: 1;" onclick="closeEditModal()">Ghairi</button>
                </div>
            </form>
        </div>
    </div>

    <script>
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
