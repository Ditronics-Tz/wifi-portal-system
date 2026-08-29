<?php
require_once dirname(__DIR__, 2) . '/src/auth.php';
require_once dirname(__DIR__, 2) . '/src/session_service.php';
startAppSession();
requireAdmin();

syncSessionsFromRadacct();
$typeFilter = $_GET['type'] ?? '';
$events = getSecurityEvents(200, $typeFilter !== '' ? $typeFilter : null);
$activePage = 'security';
$pageTitle = 'Security events';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security - Admin</title>
    <?php require dirname(__DIR__, 2) . '/src/theme_init.php'; ?>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php require dirname(__DIR__, 2) . '/src/admin_header.php'; ?>
            <div class="section-header">
                <h1>Security events</h1>
                <p>Reuse attempts, session limits, and accounting-based sharing signals. Tethering detection is best-effort.</p>
            </div>

            <div class="admin-card">
                <form method="GET" class="filters-bar">
                    <select name="type" class="filter-select">
                        <option value="">All types</option>
                        <?php foreach (['VOUCHER_REUSE','SESSION_LIMIT','MULTIPLE_DEVICE','VOUCHER_SUSPENDED','EXPIRED_VOUCHER','INVALID_VOUCHER','SESSION_STARTED','DEVICE_RELEASED'] as $t): ?>
                            <option value="<?php echo $t; ?>" <?php echo $typeFilter === $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-secondary btn-small">Filter</button>
                    <?php if ($typeFilter): ?><a href="/admin/security.php" class="btn btn-secondary btn-small" style="text-decoration: none;">Clear</a><?php endif; ?>
                </form>

                <?php if (empty($events)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><?php echo hi('Alert02Icon', 44); ?></div>
                        <div class="empty-state-title">No events yet</div>
                        <div class="empty-state-text">Blocked reuse and RADIUS accounting anomalies will show here.</div>
                    </div>
                <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Type</th>
                                <th>Severity</th>
                                <th>Voucher</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $e): ?>
                            <tr>
                                <td style="font-size: var(--text-sm);"><?php echo date('d/m H:i:s', strtotime($e['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($e['event_type']); ?></td>
                                <td><span class="badge"><?php echo htmlspecialchars($e['severity']); ?></span></td>
                                <td class="code-cell"><?php echo htmlspecialchars($e['voucher_code'] ?: '—'); ?></td>
                                <td style="font-size: var(--text-sm); max-width: 28rem;">
                                    <?php
                                    $meta = $e['metadata'];
                                    if (is_string($meta)) {
                                        echo htmlspecialchars($meta);
                                    } elseif (is_array($meta)) {
                                        echo htmlspecialchars(json_encode($meta));
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
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
