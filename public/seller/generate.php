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
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { $error = 'Ombi si sahihi.'; }
    else {
        $planKey = $_POST['plan'] ?? '';
        $quantity = intval($_POST['quantity'] ?? 0);
        $pkg = getPackageBySlug($planKey);
        if (!$pkg || !$pkg['is_active']) { $error = 'Chagua package sahihi.'; }
        elseif ($quantity < 1 || $quantity > SELLER_MAX_GENERATE_QUANTITY) { $error = 'Idadi 1-' . SELLER_MAX_GENERATE_QUANTITY . '.'; }
        elseif (!$sellerId) { $error = 'Seller ID haikupatikana.'; }
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
    if ($s < 3600) return round($s / 60) . ' dakika';
    if ($s < 86400) return round($s / 3600) . ' saa';
    return round($s / 86400) . ' siku';
}
?>
<!DOCTYPE html>
<html lang="sw">
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
                    
                    <a href="/seller/my-sales.php">Mauzo Yangu</a>
                </nav>
                <div class="admin-user">
                    <div class="admin-user-avatar"><?php echo strtoupper(substr($sellerUsername, 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($sellerUsername); ?></span>
                    <a href="/seller/logout.php" style="color: var(--text-tertiary); text-decoration: none; font-size: var(--text-xs);">Toka</a>
                </div>
            </div>
        </header>
        <main class="admin-content">
            <div class="section-header">
                <h1>Tengeneza Voucher</h1>
                <p>Tengeneza voucher kutoka packages zilizowashwa na admin.</p>
            </div>

            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-text">
                        <h2 class="admin-card-title">Fomu ya Kutengeneza</h2>
                        <p class="admin-card-subtitle">Chagua package na idadi</p>
                    </div>
                </div>
                <?php if ($error): ?><div class="alert alert-error"><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>
                <?php if (!empty($generated)): ?>
                    <div class="alert alert-success"><span>Umefanikiwa kutengeneza voucher <strong><?php echo count($generated); ?></strong> na mauzo yamerekodwa otomatiki.</span></div>
                    <div class="code-list">
                        <div class="code-list-title">Voucher Zilizotengenezwa</div>
                        <?php foreach ($generated as $code): ?><div class="code-item"><span><?php echo htmlspecialchars($code); ?></span><button class="copy-btn" onclick="copyCode('<?php echo $code; ?>', this)">Nakili</button></div><?php endforeach; ?>
                    </div>
                    <div class="action-buttons">
                        <button class="btn btn-secondary btn-small" onclick="copyAll()">Nakili Zote</button>
                        <button class="btn btn-secondary btn-small" onclick="downloadCSV()">CSV</button>
                    </div>
                    <script>
                        function copyCode(c,b){navigator.clipboard.writeText(c).then(function(){b.textContent='Imenakiliwa!';b.classList.add('copied');setTimeout(function(){b.textContent='Nakili';b.classList.remove('copied');},2000);});}
                        function copyAll(){var c=<?php echo json_encode($generated);?>;navigator.clipboard.writeText(c.join('\n')).then(function(){alert('Zote zimenakiliwa!');});}
                        function downloadCSV(){var c=<?php echo json_encode($generated);?>,p='<?php echo addslashes($planKey);?>';var v='Code,Package\n';c.forEach(function(x){v+=x+','+p+'\n';});var b=new Blob([v],{type:'text/csv'});var a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='vouchers_<?php echo date('Y-m-d_His');?>.csv';a.click();}
                    </script>
                    <hr style="border: none; border-top: 1px solid var(--border-subtle); margin: var(--space-6) 0;">
                <?php endif; ?>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <div class="form-group">
                        <label for="plan">Chagua Package</label>
                        <select name="plan" id="plan" class="form-select" required>
                            <option value="">Chagua package...</option>
                            <?php foreach ($packages as $pkg): ?>
                                <option value="<?php echo htmlspecialchars($pkg['slug']); ?>" <?php echo ($planKey === $pkg['slug']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($pkg['name']); ?> — <?php echo number_format($pkg['price']); ?> TZS (<?php echo fmtDuration((int)$pkg['duration_seconds']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="quantity">Idadi (1-<?php echo SELLER_MAX_GENERATE_QUANTITY; ?>)</label>
                        <input type="number" id="quantity" name="quantity" class="form-number" min="1" max="<?php echo SELLER_MAX_GENERATE_QUANTITY; ?>" value="<?php echo htmlspecialchars($_POST['quantity'] ?? '10'); ?>" required>
                        <div class="form-hint">Kila voucher inatengenezwa na inarekodiwa kama mauzo otomatiki.</div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="max-width: 250px;">Tengeneza na Rekodi Mauzo</button>
                </form>
            </div>

            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-text">
                        <h2 class="admin-card-title">Packages Zinazopatikana</h2>
                        <p class="admin-card-subtitle">Packages zilizowashwa na admin</p>
                    </div>
                </div>
                <?php if (empty($packages)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📦</div>
                        <div class="empty-state-title">Hakuna packages</div>
                        <div class="empty-state-text">Admin bado hajawasha packages. Wasiliana na admin.</div>
                    </div>
                <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead><tr><th>Package</th><th>Muda</th><th>Bei (TZS)</th><th>Bandwidth</th><th>Maelezo</th></tr></thead>
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
