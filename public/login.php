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
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { $error = 'Invalid request.'; }
    else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if (empty($username) || empty($password)) { $error = 'Please enter your username and password.'; }
        else {
            if (attemptAdminLogin($username, $password)) { header('Location: /admin/dashboard.php'); exit; }
            if (attemptSellerLogin($username, $password)) { header('Location: /seller/dashboard.php'); exit; }
            $error = isLoginRateLimited($username) ? 'Too many attempts. Try again in 15 minutes.' : 'Incorrect username or password.';
        }
    }
}
$csrf = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - WiFi Voucher Portal</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <div class="login-wrapper">
        <div class="card login-card">
            <div class="header">
                <div class="brand-icon"><img src="/assets/DITRONICS-COMPANY-LOGO.png" alt="Ditronics"></div>
                <h1>WiFi Portal</h1>
                <p class="subtitle">Sign in to your account</p>
            </div>

            <?php if ($error): ?><div class="alert alert-error"><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>
            <?php if (isset($_GET['logout'])): ?><div class="alert alert-success"><span>You have been signed out.</span></div><?php endif; ?>
            <?php if (isset($_GET['expired'])): ?><div class="alert alert-error"><span>Session expired. Please sign in again.</span></div><?php endif; ?>

            <form method="POST" action="" class="voucher-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrapper">
                        <input type="text" id="username" name="username" placeholder="Your username" value="<?php echo htmlspecialchars($username); ?>" required autofocus autocomplete="username">
                    </div>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" placeholder="Your password" required autocomplete="current-password">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Sign In</button>
            </form>

            <div class="info-section">
                <a href="/" class="info-link">Back to Voucher Portal</a>
            </div>
        </div>
    </div>
</body>
</html>
