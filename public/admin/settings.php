<?php
require_once dirname(__DIR__, 2) . '/src/auth.php';
startAppSession();
requireAdmin();

$activePage = '';
$pageTitle = 'Settings';
$plans = defined('PLANS') ? PLANS : [];
?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Admin</title>
    <?php require dirname(__DIR__, 2) . '/src/theme_init.php'; ?>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php require dirname(__DIR__, 2) . '/src/admin_header.php'; ?>
            <div class="section-header">
                <h1>Settings</h1>
                <p>Mipangilio ya sasa ya mfumo (soma tu).</p>
            </div>

            <div class="grid-2col">
                <div class="admin-card">
                    <div class="admin-card-header">
                        <div class="admin-card-header-text">
                            <h2 class="admin-card-title">Usalama wa Kuingia</h2>
                            <p class="admin-card-subtitle">Sheria za majaribio ya kuingia</p>
                        </div>
                    </div>
                    <div class="status-info" style="margin-bottom: 0;">
                        <div class="status-row"><span class="status-label">Muda wa Session</span><span class="status-value"><?php echo (int)(SESSION_LIFETIME / 60); ?> dakika</span></div>
                        <div class="status-row"><span class="status-label">Majaribio Kabla ya Kuzuiwa</span><span class="status-value"><?php echo defined('MAX_LOGIN_ATTEMPTS') ? MAX_LOGIN_ATTEMPTS : '—'; ?></span></div>
                        <div class="status-row"><span class="status-label">Muda wa Kuzuiwa</span><span class="status-value"><?php echo defined('LOGIN_LOCKOUT_TIME') ? (int)(LOGIN_LOCKOUT_TIME / 60) . ' dakika' : '—'; ?></span></div>
                    </div>
                </div>

                <div class="admin-card">
                    <div class="admin-card-header">
                        <div class="admin-card-header-text">
                            <h2 class="admin-card-title">Sellers</h2>
                            <p class="admin-card-subtitle">Vikomo vya uzalishaji wa voucher</p>
                        </div>
                    </div>
                    <div class="status-info" style="margin-bottom: 0;">
                        <div class="status-row"><span class="status-label">Kiwango cha Juu cha Voucher kwa Wakati Mmoja</span><span class="status-value"><?php echo defined('SELLER_MAX_GENERATE_QUANTITY') ? SELLER_MAX_GENERATE_QUANTITY : '—'; ?></span></div>
                    </div>
                </div>
            </div>

            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-text">
                        <h2 class="admin-card-title">Voucher Plans</h2>
                        <p class="admin-card-subtitle">Package zilizowekwa kwenye mfumo</p>
                    </div>
                    <a href="/admin/packages.php" class="btn btn-ghost btn-tiny">Simamia Packages</a>
                </div>
                <?php if (empty($plans)): ?>
                    <p style="text-align: center; color: var(--text-tertiary); padding: var(--space-6);">Hakuna plan zilizowekwa.</p>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead><tr><th>Jina</th><th>Muda</th><th>Bei (TZS)</th></tr></thead>
                            <tbody>
                                <?php foreach ($plans as $plan): ?>
                                <tr>
                                    <td style="font-weight: 500;"><?php echo htmlspecialchars($plan['name']); ?></td>
                                    <td><?php echo number_format($plan['duration_seconds'] / 86400, 0); ?> siku</td>
                                    <td><?php echo number_format($plan['price']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
<?php require dirname(__DIR__, 2) . '/src/admin_footer.php'; ?>
</body>
</html>
