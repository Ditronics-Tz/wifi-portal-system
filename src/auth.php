<?php
/**
 * Admin authentication helper
 */

require_once '/var/www/voucher-portal/config.php';
require_once '/var/www/voucher-portal/src/db.php';

/**
 * Check if admin is logged in
 */
function isAdminLoggedIn() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Check session timeout
    if (isset($_SESSION['admin_last_activity'])) {
        if (time() - $_SESSION['admin_last_activity'] > SESSION_LIFETIME) {
            // Session expired
            session_destroy();
            return false;
        }
    }
    
    return !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * Update admin session activity timestamp
 */
function updateAdminActivity() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['admin_last_activity'] = time();
}

/**
 * Check login rate limiting
 * @return bool True if rate limited (should block)
 */
function isLoginRateLimited($username) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $key = 'login_attempts_' . $username;
    
    if (!isset($_SESSION[$key])) {
        return false;
    }
    
    $attempts = $_SESSION[$key];
    
    // Check if locked out
    if ($attempts['count'] >= MAX_LOGIN_ATTEMPTS) {
        $lockoutEnd = $attempts['first_attempt'] + LOGIN_LOCKOUT_TIME;
        if (time() < $lockoutEnd) {
            return true;
        } else {
            // Lockout expired, reset
            unset($_SESSION[$key]);
            return false;
        }
    }
    
    return false;
}

/**
 * Record failed login attempt
 */
function recordLoginAttempt($username) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $key = 'login_attempts_' . $username;
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [
            'count' => 1,
            'first_attempt' => time()
        ];
    } else {
        $_SESSION[$key]['count']++;
        
        // Reset if too much time has passed
        if (time() - $_SESSION[$key]['first_attempt'] > LOGIN_LOCKOUT_TIME) {
            $_SESSION[$key] = [
                'count' => 1,
                'first_attempt' => time()
            ];
        }
    }
}

/**
 * Clear login attempts on successful login
 */
function clearLoginAttempts($username) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $key = 'login_attempts_' . $username;
    unset($_SESSION[$key]);
}

/**
 * Attempt admin login
 * 
 * @param string $username
 * @param string $password
 * @return bool True if login successful
 */
function attemptAdminLogin($username, $password) {
    // Check rate limit
    if (isLoginRateLimited($username)) {
        error_log("Rate limited login attempt for: $username");
        return false;
    }
    
    // For v1: Use config file for credentials
    // In production, consider using admins table
    if ($username === ADMIN_USERNAME && password_verify($password, ADMIN_PASSWORD_HASH)) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_last_activity'] = time();
        
        clearLoginAttempts($username);
        return true;
    }
    
    // Record failed attempt
    recordLoginAttempt($username);
    
    // Log for security monitoring
    error_log("Failed admin login attempt for: $username from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    
    return false;
}

/**
 * Admin logout
 */
function adminLogout() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $_SESSION = [];
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}

/**
 * Require admin authentication - redirects to login if not authenticated
 */
function requireAdmin() {
    if (!isAdminLoggedIn()) {
        header('Location: /admin/login.php');
        exit;
    }
    updateAdminActivity();
}
