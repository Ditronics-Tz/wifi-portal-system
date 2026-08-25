<?php
require_once dirname(__DIR__, 2) . '/src/auth.php';
require_once dirname(__DIR__, 2) . '/src/voucher_service.php';
require_once dirname(__DIR__, 2) . '/src/package_service.php';
startAppSession();
requireAdmin();

$generated = [];
$error = null;
$message = null;
$planId = 0;
$packages = getAllPackages();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { $error = 'Invalid request.'; }
    else {
        $postAction = $_POST['action'] ?? 'generate';
        if ($postAction === 'expire' && isset($_POST['code'])) {
            $code = preg_replace('/[^A-Z0-9]/', '', $_POST['code']);
            if (!empty($code)) { forceExpireVoucher($code); $message = "Voucher $code expired."; }
        } elseif ($postAction === 'release_mac' && isset($_POST['code'])) {
            $code = preg_replace('/[^A-Z0-9]/', '', $_POST['code']);
            if (!empty($code)) {
                if (releaseVoucherDevice($code)) {
                    writeAuditLog('voucher_release_device', getCurrentUserId(), 'voucher', $code);
                    $message = "Voucher $code released — it can now be used on a new device.";
                } else {
                    $error = "Voucher $code is not active or was not found.";
                }
            }
        } else {
            $planId = intval($_POST['plan'] ?? 0);
            $quantity = intval($_POST['quantity'] ?? 0);
            $pkg = $planId > 0 ? getPackageById($planId) : null;
            if (!$pkg || !$pkg['is_active']) { $error = 'Choose a valid package.'; }
            elseif ($quantity < 1 || $quantity > 100) { $error = 'Quantity must be 1-100.'; }
            else {
                try {
                    $generated = generateVouchers(
                        $pkg['name'],
                        (int) $pkg['duration_seconds'],
                        (float) $pkg['price'],
                        $quantity,
                        $_SESSION['admin_username'],
                        null
                    );
                } catch (Exception $e) { $error = $e->getMessage(); }
            }
        }
    }
}
$csrf = generateCSRFToken();

function fmtDuration(int $s): string {
    if ($s < 3600) return round($s / 60) . ' min';
    if ($s < 86400) return round($s / 3600) . ' hr';
    return round($s / 86400) . ' day(s)';
}

// ── Voucher list (statistics + filters) ────────────────────────────
$statusFilter = $_GET['status'] ?? '';
$planFilter = $_GET['plan_name'] ?? '';
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$stats = countVouchersByStatus();
$vouchers = getVouchers($statusFilter ?: null, $search ?: null, null, $planFilter ?: null, $perPage, $offset);
$totalResults = count(getVouchers($statusFilter ?: null, $search ?: null, null, $planFilter ?: null, 10000, 0));
$totalPages = ceil($totalResults / $perPage);

$activePage = 'generate';
$pageTitle = 'Vouchers';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vouchers - Admin</title>
    <?php require dirname(__DIR__, 2) . '/src/theme_init.php'; ?>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php require dirname(__DIR__, 2) . '/src/admin_header.php'; ?>
            <div class="section-header">
                <h1>Vouchers</h1>
                <p>Generate, filter, and manage vouchers across all packages.</p>
            </div>

            <?php if ($message): ?><div class="alert alert-success"><span><?php echo htmlspecialchars($message); ?></span></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>

            <?php if (!empty($generated)): ?>
            <div class="admin-card">
                <div class="admin-card-header"><h2 class="admin-card-title">Generated Vouchers</h2></div>
                <div class="alert alert-success"><span>Successfully generated <strong><?php echo count($generated); ?></strong> voucher(s). Added to stock — record the sale on the <a href="/admin/sales.php">Sales</a> page when sold.</span></div>
                <div class="code-list">
                    <div class="code-list-title">Generated Vouchers</div>
                    <?php foreach ($generated as $code): ?><div class="code-item"><span><?php echo htmlspecialchars($code); ?></span><button class="copy-btn" onclick="copyCode('<?php echo $code; ?>', this)">Copy</button></div><?php endforeach; ?>
                </div>
                <div class="action-buttons">
                    <button class="btn btn-secondary btn-small" onclick="copyAll()">Copy All</button>
                    <button class="btn btn-secondary btn-small" onclick="downloadCSV()">CSV</button>
                </div>
                <script>
                    function copyCode(c,b){navigator.clipboard.writeText(c).then(function(){b.textContent='Copied!';b.classList.add('copied');setTimeout(function(){b.textContent='Copy';b.classList.remove('copied');},2000);});}
                    function copyAll(){var c=<?php echo json_encode($generated);?>;navigator.clipboard.writeText(c.join('\n')).then(function(){alert('All copied!');});}
                    function downloadCSV(){var c=<?php echo json_encode($generated);?>,p='<?php echo addslashes($planKey);?>';var v='Code,Package\n';c.forEach(function(x){v+=x+','+p+'\n';});var b=new Blob([v],{type:'text/csv'});var a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='vouchers_<?php echo date('Y-m-d_His');?>.csv';a.click();}
                </script>
            </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-card-header"><div class="stat-icon-wrap icon-primary"><?php echo hi('Ticket01Icon', 20); ?></div></div><div class="stat-value"><?php echo number_format($stats['total'] ?? 0); ?></div><div class="stat-label">Total Vouchers</div></div>
                <div class="stat-card"><div class="stat-card-header"><div class="stat-icon-wrap icon-success"><?php echo hi('CheckmarkCircle01Icon', 20); ?></div></div><div class="stat-value"><?php echo number_format($stats['unused'] ?? 0); ?></div><div class="stat-label">Unused</div></div>
                <div class="stat-card"><div class="stat-card-header"><div class="stat-icon-wrap icon-secondary"><?php echo hi('Wifi01Icon', 20); ?></div></div><div class="stat-value"><?php echo number_format($stats['active'] ?? 0); ?></div><div class="stat-label">Active</div></div>
                <div class="stat-card"><div class="stat-card-header"><div class="stat-icon-wrap icon-warning"><?php echo hi('Alert02Icon', 20); ?></div></div><div class="stat-value"><?php echo number_format($stats['expired'] ?? 0); ?></div><div class="stat-label">Expired</div></div>
            </div>

            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-text">
                        <h2 class="admin-card-title">Voucher List</h2>
                        <p class="admin-card-subtitle">Unused and active vouchers (expired vouchers are hidden)</p>
                    </div>
                    <button type="button" class="btn btn-primary btn-small" onclick="openGenerateModal()"><?php echo hi('Ticket01Icon', 16); ?> Generate Voucher</button>
                </div>

                <form method="GET" action="" class="filters-bar">
                    <input type="text" name="search" class="filter-input" placeholder="Search code..." value="<?php echo htmlspecialchars($search); ?>">
                    <select name="status" class="filter-select">
                        <option value="">All Statuses</option>
                        <option value="unused" <?php echo $statusFilter === 'unused' ? 'selected' : ''; ?>>Unused</option>
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                    </select>
                    <select name="plan_name" class="filter-select">
                        <option value="">All Packages</option>
                        <?php foreach ($packages as $pkg): ?>
                            <option value="<?php echo htmlspecialchars($pkg['name']); ?>" <?php echo $planFilter === $pkg['name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($pkg['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-secondary btn-small">Search</button>
                    <?php if ($statusFilter || $planFilter || $search): ?><a href="/admin/generate.php" class="btn btn-secondary btn-small" style="text-decoration: none;">Clear</a><?php endif; ?>
                </form>

                <?php if (empty($vouchers) && !$statusFilter && !$planFilter && !$search): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><?php echo hi('Ticket01Icon', 44); ?></div>
                        <div class="empty-state-title">No vouchers yet</div>
                        <div class="empty-state-text">Click "Generate Voucher" above to create new vouchers.</div>
                    </div>
                <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead><tr><th>Code</th><th>Package</th><th>Status</th><th>Created</th><th>Started</th><th>Expires</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php if (empty($vouchers)): ?>
                                <tr><td colspan="7" style="text-align: center; color: var(--text-tertiary); padding: var(--space-8);">No vouchers</td></tr>
                            <?php else: foreach ($vouchers as $v): ?>
                            <tr>
                                <td class="code-cell"><?php echo htmlspecialchars($v['code']); ?></td>
                                <td><?php echo htmlspecialchars($v['plan_name']); ?></td>
                                <td><span class="badge badge-<?php echo $v['status']; ?>"><?php echo $v['status']==='unused'?'Unused':'Active'; ?></span></td>
                                <td style="font-size: var(--text-sm);"><?php echo date('d/m H:i', strtotime($v['created_at'])); ?></td>
                                <td style="font-size: var(--text-sm);"><?php echo $v['first_used_at'] ? date('d/m H:i', strtotime($v['first_used_at'])) : '—'; ?></td>
                                <td style="font-size: var(--text-sm);"><?php echo $v['expires_at'] ? date('d/m H:i', strtotime($v['expires_at'])) : '—'; ?></td>
                                <td>
                                    <?php if ($v['status'] === 'active'): ?>
                                        <form method="POST" style="display: inline;" data-confirm="End this voucher? The user will be disconnected."><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="expire"><input type="hidden" name="code" value="<?php echo htmlspecialchars($v['code']); ?>"><button type="submit" class="btn btn-tiny btn-danger">End</button></form>
                                        <?php if (!empty($v['first_mac'])): ?>
                                        <form method="POST" style="display: inline;" data-confirm="Release this voucher from its current device? It will then be usable on a new device without losing remaining time."><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="release_mac"><input type="hidden" name="code" value="<?php echo htmlspecialchars($v['code']); ?>"><button type="submit" class="btn btn-tiny btn-secondary" title="Bound to <?php echo htmlspecialchars($v['first_mac']); ?>">Release device</button></form>
                                        <?php endif; ?>
                                    <?php else: ?><span style="color: var(--text-tertiary);">—</span><?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?><a href="?page=<?php echo $page-1; ?>&status=<?php echo urlencode($statusFilter); ?>&plan_name=<?php echo urlencode($planFilter); ?>&search=<?php echo urlencode($search); ?>">Back</a><?php endif; ?>
                    <?php for ($i = max(1, $page-2); $i <= min($totalPages, $page+2); $i++): ?><a href="?page=<?php echo $i; ?>&status=<?php echo urlencode($statusFilter); ?>&plan_name=<?php echo urlencode($planFilter); ?>&search=<?php echo urlencode($search); ?>" class="<?php echo $i===$page?'active':''; ?>"><?php echo $i; ?></a><?php endfor; ?>
                    <?php if ($page < $totalPages): ?><a href="?page=<?php echo $page+1; ?>&status=<?php echo urlencode($statusFilter); ?>&plan_name=<?php echo urlencode($planFilter); ?>&search=<?php echo urlencode($search); ?>">Next</a><?php endif; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
<?php require dirname(__DIR__, 2) . '/src/admin_footer.php'; ?>

    <!-- Generate Modal -->
    <div id="generateModal" class="modal-overlay <?php echo $error ? 'open' : ''; ?>">
        <div class="modal">
            <h3 class="modal-title">Generate Vouchers</h3>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="generate">
                <div class="form-group">
                    <label for="plan">Choose Package</label>
                    <select name="plan" id="plan" class="form-select" required>
                        <option value="">Select a package...</option>
                        <?php foreach ($packages as $pkg): if (!$pkg['is_active']) continue; ?>
                            <option value="<?php echo (int) $pkg['id']; ?>" <?php echo ((int) $planId === (int) $pkg['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($pkg['name']); ?> — <?php echo number_format($pkg['price']); ?> TZS (<?php echo fmtDuration((int)$pkg['duration_seconds']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="quantity">Quantity (1-100)</label>
                    <input type="number" id="quantity" name="quantity" class="form-number" min="1" max="100" value="<?php echo htmlspecialchars($_POST['quantity'] ?? '10'); ?>" required>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary btn-small" style="flex: 1;">Generate Vouchers</button>
                    <button type="button" class="btn btn-secondary btn-small" style="flex: 1;" onclick="closeGenerateModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openGenerateModal() { document.getElementById('generateModal').classList.add('open'); }
        function closeGenerateModal() { document.getElementById('generateModal').classList.remove('open'); }
        document.getElementById('generateModal').addEventListener('click', function(e) { if (e.target === this) closeGenerateModal(); });
    </script>
</body>
</html>
