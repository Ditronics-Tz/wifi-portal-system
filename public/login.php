<?php
require_once dirname(__DIR__) . '/src/auth.php';
require_once dirname(__DIR__) . '/src/user_service.php';
startAppSession();

$currentRole = getCurrentRole();
if ($currentRole === 'admin') { header('Location: /admin/dashboard.php'); exit; }
if ($currentRole === 'seller') { header('Location: /seller/dashboard.php'); exit; }

$error = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { $error = 'Ombi si sahihi.'; }
    else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if (empty($username) || empty($password)) { $error = 'Tafadhali jaza majina na nywila.'; }
        else {
            if (attemptAdminLogin($username, $password)) { header('Location: /admin/dashboard.php'); exit; }
            if (attemptSellerLogin($username, $password)) { header('Location: /seller/dashboard.php'); exit; }
            $error = isLoginRateLimited($username) ? 'Majaribio mengi. Jaribu baada ya dakika 15.' : 'Majina au nywila si sahihi.';
        }
    }
}
$csrf = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingia - WiFi Voucher Portal</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <div class="login-wrapper">
        <div class="card login-card">
            <div class="header">
                <div class="brand-icon"><img src="/assets/DITRONICS-COMPANY-LOGO.png" alt="Ditronics"></div>
                <h1>WiFi Portal</h1>
                <p class="subtitle">Ingia kwenye akaunti yako</p>
            </div>

            <?php if ($error): ?><div class="alert alert-error"><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>
            <?php if (isset($_GET['logout'])): ?><div class="alert alert-success"><span>Umefanikiwa kutoka.</span></div><?php endif; ?>
            <?php if (isset($_GET['expired'])): ?><div class="alert alert-error"><span>Session imeisha. Ingia tena.</span></div><?php endif; ?>

            <form method="POST" action="" class="voucher-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <div class="form-group">
                    <label for="username">Jina la Mtumiaji</label>
                    <div class="input-wrapper">
                        <input type="text" id="username" name="username" placeholder="Jina lako" value="<?php echo htmlspecialchars($username); ?>" required autofocus autocomplete="username">
                    </div>
                </div>
                <div class="form-group">
                    <label for="password">Nywila</label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" placeholder="Nywila yako" required autocomplete="current-password">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Ingia</button>
            </form>

            <div class="info-section">
                <a href="/" class="info-link">Rudi kwenye Voucher Portal</a>
            </div>
        </div>
    </div>
</body>
</html>
