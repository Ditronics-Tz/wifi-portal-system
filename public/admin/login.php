<?php
/**
 * Admin login page
 */

require_once '/var/www/voucher-portal/src/auth.php';
require_once '/var/www/voucher-portal/src/db.php';

session_start();

if (isAdminLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    
    if (empty($username) || empty($password)) {
        $error = 'Tafadhali jaza majina na nywila.';
    } else {
        if (attemptAdminLogin($username, $password)) {
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Majina au nywila si sahihi.';
            if (isLoginRateLimited($username)) {
                $error = 'Majaribio mengi sana. Jaribu tena baada ya dakika 15.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - WiFi Portal</title>
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
</head>
<body>
    <div class="login-wrapper">
        <div class="card login-card">
            <div class="header">
                <div class="brand-icon">🔐</div>
                <h1>Admin Panel</h1>
                <p class="subtitle">Ingia kudhibiti voucher</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['logout'])): ?>
                <div class="alert alert-success">
                    <span>Umefanikiwa kutoka. Kwaheri!</span>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" class="voucher-form">
                <div class="form-group">
                    <label for="username">Jina la Mtumiaji</label>
                    <div class="input-wrapper">
                        <input 
                            type="text" 
                            id="username" 
                            name="username" 
                            placeholder="admin"
                            value="<?php echo htmlspecialchars($username); ?>"
                            required
                            autofocus
                        >
                        <span class="input-icon">👤</span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Nywila</label>
                    <div class="input-wrapper">
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            placeholder="••••••••"
                            required
                        >
                        <span class="input-icon">🔒</span>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <span>Ingia</span>
                </button>
            </form>
            
            <div class="info-section">
                <a href="/" class="info-link">← Rudi kwenye Voucher Portal</a>
            </div>
        </div>
    </div>
</body>
</html>
