<?php
require_once dirname(__DIR__, 2) . '/src/auth.php';
startAppSession();
requireAdmin();

$adminUsername = getCurrentUsername();
$activePage = '';
$pageTitle = 'Profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Admin</title>
    <?php require dirname(__DIR__, 2) . '/src/theme_init.php'; ?>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php require dirname(__DIR__, 2) . '/src/admin_header.php'; ?>
            <div class="section-header">
                <h1>Profile</h1>
                <p>Your admin account information.</p>
            </div>

            <div class="admin-card" style="max-width: 480px;">
                <div style="display: flex; align-items: center; gap: var(--space-4); margin-bottom: var(--space-6);">
                    <span class="admin-user-avatar admin-user-avatar-lg" style="width: 56px; height: 56px; font-size: var(--text-xl);"><?php echo strtoupper(substr($adminUsername, 0, 1)); ?></span>
                    <div>
                        <div style="font-size: var(--text-lg); font-weight: 700; color: var(--text-primary);"><?php echo htmlspecialchars($adminUsername); ?></div>
                        <span class="badge badge-secondary">Administrator</span>
                    </div>
                </div>

                <div class="status-info" style="margin-bottom: 0;">
                    <div class="status-row"><span class="status-label">Username</span><span class="status-value"><?php echo htmlspecialchars($adminUsername); ?></span></div>
                    <div class="status-row"><span class="status-label">Account Type</span><span class="status-value">Administrator</span></div>
                    <div class="status-row"><span class="status-label">Session Lifetime</span><span class="status-value"><?php echo (int)(SESSION_LIFETIME / 60); ?> minutes</span></div>
                </div>
            </div>

            <div class="admin-card" style="max-width: 480px;">
                <div class="admin-card-header">
                    <div class="admin-card-header-text">
                        <h2 class="admin-card-title">Password</h2>
                        <p class="admin-card-subtitle">To change the admin password, contact the system developer.</p>
                    </div>
                </div>
                <a href="/admin/logout.php" class="btn btn-secondary btn-small" style="text-decoration: none;">Log Out</a>
            </div>
<?php require dirname(__DIR__, 2) . '/src/admin_footer.php'; ?>
</body>
</html>
