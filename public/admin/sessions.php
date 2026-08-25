<?php
require_once dirname(__DIR__, 2) . '/src/auth.php';
require_once dirname(__DIR__, 2) . '/src/session_service.php';
require_once dirname(__DIR__, 2) . '/src/voucher_service.php';
startAppSession();
requireAdmin();

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } elseif (($_POST['action'] ?? '') === 'disconnect' && !empty($_POST['code'])) {
        $code = preg_replace('/[^A-Z0-9]/', '', $_POST['code']);
        if ($code) {
            if (forceExpireVoucher($code)) {
                $message = "Session ended for $code.";
            } else {
                $error = "Could not end voucher $code.";
            }
        }
    }
}

syncSessionsFromRadacct();
$sessions = getAdminActiveSessions(200);
$csrf = generateCSRFToken();
$activePage = 'sessions';
$pageTitle = 'Sessions';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sessions - Admin</title>
    <?php require dirname(__DIR__, 2) . '/src/theme_init.php'; ?>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php require dirname(__DIR__, 2) . '/src/admin_header.php'; ?>
            <div class="section-header">
                <h1>Active sessions</h1>
                <p>Live voucher sessions. MAC and IP are supporting identifiers; the session row is the source of truth.</p>
            </div>

            <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <div class="admin-card">
                <?php if (empty($sessions)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><?php echo hi('Wifi01Icon', 44); ?></div>
                        <div class="empty-state-title">No active sessions</div>
                        <div class="empty-state-text">Sessions appear when a voucher is redeemed, and from RADIUS accounting if the AP reports it.</div>
                    </div>
                <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Voucher</th>
                                <th>Package</th>
                                <th>MAC</th>
                                <th>IP</th>
                                <th>Acct ID</th>
                                <th>Last seen</th>
                                <th>Expires</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sessions as $s): ?>
                            <tr>
                                <td class="code-cell"><?php echo htmlspecialchars($s['code']); ?></td>
                                <td><?php echo htmlspecialchars($s['plan_name']); ?></td>
                                <td style="font-size: var(--text-sm);"><?php echo htmlspecialchars($s['client_mac'] ?: '—'); ?></td>
                                <td style="font-size: var(--text-sm);"><?php echo htmlspecialchars($s['client_ip'] ?: '—'); ?></td>
                                <td style="font-size: var(--text-sm);"><?php echo htmlspecialchars($s['gateway_session_id'] ?: '—'); ?></td>
                                <td style="font-size: var(--text-sm);"><?php echo date('d/m H:i', strtotime($s['last_seen_at'])); ?></td>
                                <td style="font-size: var(--text-sm);"><?php echo !empty($s['expires_at']) ? date('d/m H:i', strtotime($s['expires_at'])) : '—'; ?></td>
                                <td>
                                    <form method="POST" style="display: inline;" data-confirm="Expire this voucher and send a disconnect to the AP?">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                        <input type="hidden" name="action" value="disconnect">
                                        <input type="hidden" name="code" value="<?php echo htmlspecialchars($s['code']); ?>">
                                        <button type="submit" class="btn btn-tiny btn-danger">Disconnect</button>
                                    </form>
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
