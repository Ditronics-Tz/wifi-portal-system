<?php
require_once dirname(__DIR__, 2) . '/src/auth.php';
require_once dirname(__DIR__, 2) . '/src/voucher_service.php';
require_once dirname(__DIR__, 2) . '/src/package_service.php';
startAppSession();
requireAdmin();

$generated = [];
$error = null;
$planKey = '';
$packages = getAllPackages();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { $error = 'Ombi si sahihi.'; }
    else {
        $planKey = $_POST['plan'] ?? '';
        $quantity = intval($_POST['quantity'] ?? 0);
        // Find package by slug
        $pkg = getPackageBySlug($planKey);
        if (!$pkg || !$pkg['is_active']) { $error = 'Chagua package sahihi.'; }
        elseif ($quantity < 1 || $quantity > 100) { $error = 'Idadi lazima iwe 1-100.'; }
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
$csrf = generateCSRFToken();

function fmtDuration(int $s): string {
    if ($s < 3600) return round($s / 60) . ' dakika';
    if ($s < 86400) return round($s / 3600) . ' saa';
    return round($s / 86400) . ' siku';
}
$activePage = 'generate';
$pageTitle = 'Generate';
?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate - Admin</title>
    <?php require dirname(__DIR__, 2) . '/src/theme_init.php'; ?>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php require dirname(__DIR__, 2) . '/src/admin_header.php'; ?>
            <div class="section-header">
                <h1>Tengeneza Voucher</h1>
                <p>Tengeneza voucher mpya kwa ajili ya packages zilizopo.</p>
            </div>

            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-text">
                        <h2 class="admin-card-title">Fomu ya Kutengeneza</h2>
                        <p class="admin-card-subtitle">Chagua package na idadi ya voucher</p>
                    </div>
                </div>
                <?php if ($error): ?><div class="alert alert-error"><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>
                <?php if (!empty($generated)): ?>
                    <div class="alert alert-success"><span>Umefanikiwa kutengeneza voucher <strong><?php echo count($generated); ?></strong>. Mauzo yamerekodwa otomatiki.</span></div>
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
                            <?php foreach ($packages as $pkg): if (!$pkg['is_active']) continue; ?>
                                <option value="<?php echo htmlspecialchars($pkg['slug']); ?>" <?php echo ($planKey === $pkg['slug']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($pkg['name']); ?> — <?php echo number_format($pkg['price']); ?> TZS (<?php echo fmtDuration((int)$pkg['duration_seconds']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="quantity">Idadi (1-100)</label>
                        <input type="number" id="quantity" name="quantity" class="form-number" min="1" max="100" value="<?php echo htmlspecialchars($_POST['quantity'] ?? '10'); ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="max-width: 250px;">Tengeneza Voucher</button>
                </form>
            </div>

            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-text">
                        <h2 class="admin-card-title">Packages Zilizopo</h2>
                        <p class="admin-card-subtitle">Packages ambazo sellers wanaweza kutumia</p>
                    </div>
                    <a href="/admin/packages.php" class="btn btn-ghost btn-tiny">Simamia</a>
                </div>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead><tr><th>Package</th><th>Muda</th><th>Bei (TZS)</th><th>Bandwidth</th><th>Hali</th></tr></thead>
                        <tbody>
                            <?php foreach ($packages as $pkg): ?>
                            <tr>
                                <td style="font-weight: 500;"><?php echo htmlspecialchars($pkg['name']); ?></td>
                                <td><?php echo fmtDuration((int)$pkg['duration_seconds']); ?></td>
                                <td style="font-weight: 600; color: var(--color-secondary);"><?php echo number_format($pkg['price']); ?></td>
                                <td><?php echo $pkg['bandwidth_mbps'] ? $pkg['bandwidth_mbps'] . ' Mbps' : '—'; ?></td>
                                <td><span class="badge <?php echo $pkg['is_active'] ? 'badge-active' : 'badge-expired'; ?>"><?php echo $pkg['is_active'] ? 'Hai' : 'Imezimwa'; ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
<?php require dirname(__DIR__, 2) . '/src/admin_footer.php'; ?>
</body>
</html>
