<?php
require_once dirname(__DIR__, 2) . '/src/auth.php';
require_once dirname(__DIR__, 2) . '/src/voucher_service.php';
require_once dirname(__DIR__, 2) . '/src/package_service.php';
startAppSession();
requireSellerOrAdmin();

$sellerId = getCurrentUserId();
$sellerUsername = getCurrentUsername();
$generated = [];
$error = null;
$planKey = '';
$packages = getActivePackages();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { $error = 'Invalid request.'; }
    else {
        $planKey = $_POST['plan'] ?? '';
        $quantity = intval($_POST['quantity'] ?? 0);
        $pkg = getPackageBySlug($planKey);
        if (!$pkg || !$pkg['is_active']) { $error = 'Choose a valid package.'; }
        elseif ($quantity < 1 || $quantity > SELLER_MAX_GENERATE_QUANTITY) { $error = 'Quantity must be 1-' . SELLER_MAX_GENERATE_QUANTITY . '.'; }
        elseif (!$sellerId) { $error = 'Seller ID not found.'; }
        else {
            try {
                $generated = generateVouchers(
                    $pkg['name'],
                    (int) $pkg['duration_seconds'],
                    (float) $pkg['price'],
                    $quantity,
                    $sellerUsername,
                    $sellerId
                );
                writeAuditLog('vouchers_generated', $sellerId, 'vouchers', null, ['package' => $planKey, 'quantity' => $quantity]);
            } catch (Exception $e) { $error = $e->getMessage(); }
        }
    }
}
$csrf = generateCSRFToken();

function fmtDuration(int $s): string {
    if ($s < 3600) return round($s / 60) . ' min';
    if ($s < 86400) return round($s / 3600) . ' hr';
    return round($s / 86400) . ' day(s)';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate - Seller</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <div class="admin-wrapper">
        <header class="admin-header">
            <div class="admin-header-inner">
                <a href="/seller/dashboard.php" class="admin-logo"><img src="/assets/DITRONICS-COMPANY-LOGO.png" alt="Ditronics" style="height:28px;width:auto;"><span class="admin-logo-text">WiFi Voucher Seller</span></a>
                <nav class="admin-nav">
                    <a href="/seller/dashboard.php">Dashboard</a>
                    <a href="/seller/generate.php" class="active">Generate</a>

                    <a href="/seller/my-sales.php">My Sales</a>
                </nav>
                <div class="admin-user">
                    <div class="admin-user-avatar"><?php echo strtoupper(substr($sellerUsername, 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($sellerUsername); ?></span>
                    <a href="/seller/logout.php" style="color: var(--text-tertiary); text-decoration: none; font-size: var(--text-xs);">Logout</a>
                </div>
            </div>
        </header>
        <main class="admin-content">
            <div class="section-header">
                <h1>Generate Vouchers</h1>
                <p>Generate vouchers from packages activated by the admin.</p>
            </div>

            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-text">
                        <h2 class="admin-card-title">Generate Form</h2>
                        <p class="admin-card-subtitle">Choose a package and quantity</p>
                    </div>
                </div>
                <?php if ($error): ?><div class="alert alert-error"><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>
                <?php if (!empty($generated)): ?>
                    <div class="alert alert-success"><span>Successfully generated <strong><?php echo count($generated); ?></strong> voucher(s). Added to your stock — record the sale from <a href="/seller/record-sale.php">Record Sale</a> when sold.</span></div>
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
                    <hr style="border: none; border-top: 1px solid var(--border-subtle); margin: var(--space-6) 0;">
                <?php endif; ?>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <div class="form-group">
                        <label for="plan">Choose Package</label>
                        <select name="plan" id="plan" class="form-select" required>
                            <option value="">Select a package...</option>
                            <?php foreach ($packages as $pkg): ?>
                                <option value="<?php echo htmlspecialchars($pkg['slug']); ?>" <?php echo ($planKey === $pkg['slug']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($pkg['name']); ?> — <?php echo number_format($pkg['price']); ?> TZS (<?php echo fmtDuration((int)$pkg['duration_seconds']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="quantity">Quantity (1-<?php echo SELLER_MAX_GENERATE_QUANTITY; ?>)</label>
                        <input type="number" id="quantity" name="quantity" class="form-number" min="1" max="<?php echo SELLER_MAX_GENERATE_QUANTITY; ?>" value="<?php echo htmlspecialchars($_POST['quantity'] ?? '10'); ?>" required>
                        <div class="form-hint">Each voucher is added to your stock. Record the sale separately when it's sold.</div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="max-width: 250px;">Generate Vouchers</button>
                </form>
            </div>

            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-text">
                        <h2 class="admin-card-title">Available Packages</h2>
                        <p class="admin-card-subtitle">Packages activated by the admin</p>
                    </div>
                </div>
                <?php if (empty($packages)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📦</div>
                        <div class="empty-state-title">No packages</div>
                        <div class="empty-state-text">The admin hasn't activated any packages yet. Contact your admin.</div>
                    </div>
                <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead><tr><th>Package</th><th>Duration</th><th>Price (TZS)</th><th>Bandwidth</th><th>Description</th></tr></thead>
                        <tbody>
                            <?php foreach ($packages as $pkg): ?>
                            <tr>
                                <td style="font-weight: 500;"><?php echo htmlspecialchars($pkg['name']); ?></td>
                                <td><?php echo fmtDuration((int)$pkg['duration_seconds']); ?></td>
                                <td style="font-weight: 600; color: var(--color-secondary);"><?php echo number_format($pkg['price']); ?></td>
                                <td><?php echo $pkg['bandwidth_mbps'] ? $pkg['bandwidth_mbps'] . ' Mbps' : '—'; ?></td>
                                <td style="color: var(--text-tertiary); font-size: var(--text-sm);"><?php echo htmlspecialchars($pkg['description'] ?? '—'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
