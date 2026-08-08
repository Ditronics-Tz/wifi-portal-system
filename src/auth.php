<?php
/**
 * Authentication & Role-Based Access Control (RBAC)
 * v2: Supports admin (config-based), seller, and buyer (database-based) roles
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/db.php';

// ── Session Helpers ─────────────────────────────────────────────

/**
 * Start session with configured name and lifetime
 */
function startAppSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        if (defined('SESSION_NAME')) {
            session_name(SESSION_NAME);
        }

        // Secure session settings
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', (defined('SESSION_COOKIE_SECURE') && SESSION_COOKIE_SECURE) ? '1' : '0');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_start();
    }
}

// ── Login Attempt Tracking ──────────────────────────────────────

/**
 * Check if a username is rate-limited for login
 */
function isLoginRateLimited(string $username): bool {
    startAppSession();

    $key = 'login_attempts_' . $username;

    if (!isset($_SESSION[$key])) {
        return false;
    }

    $attempts = $_SESSION[$key];

    if ($attempts['count'] >= MAX_LOGIN_ATTEMPTS) {
        $lockoutEnd = $attempts['first_attempt'] + LOGIN_LOCKOUT_TIME;
        if (time() < $lockoutEnd) {
            return true;
        } else {
            unset($_SESSION[$key]);
            return false;
        }
    }

    return false;
}

/**
 * Record a failed login attempt
 */
function recordLoginAttempt(string $username): void {
    startAppSession();

    $key = 'login_attempts_' . $username;

    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 1, 'first_attempt' => time()];
    } else {
        $_SESSION[$key]['count']++;
        if (time() - $_SESSION[$key]['first_attempt'] > LOGIN_LOCKOUT_TIME) {
            $_SESSION[$key] = ['count' => 1, 'first_attempt' => time()];
        }
    }
}

/**
 * Clear login attempts on successful login
 */
function clearLoginAttempts(string $username): void {
    startAppSession();
    unset($_SESSION['login_attempts_' . $username]);
}

// ── Session State Checks ────────────────────────────────────────

/**
 * Get the current logged-in user's role (or null if not logged in)
 */
function getCurrentRole(): ?string {
    startAppSession();

    if (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
        return 'admin';
    }
    if (!empty($_SESSION['seller_logged_in']) && $_SESSION['seller_logged_in'] === true) {
        // Verify session hasn't expired
        if (isset($_SESSION['last_activity'])) {
            if (time() - $_SESSION['last_activity'] > SESSION_LIFETIME) {
                session_destroy();
                return null;
            }
        }
        return 'seller';
    }

    return null;
}

/**
 * Get current user's ID (null for config-based admin)
 */
function getCurrentUserId(): ?int {
    startAppSession();
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/**
 * Get current username
 */
function getCurrentUsername(): string {
    startAppSession();
    return $_SESSION['admin_username'] ?? $_SESSION['seller_username'] ?? 'unknown';
}

/**
 * Check if admin is logged in (backward-compatible with v1)
 */
function isAdminLoggedIn(): bool {
    return getCurrentRole() === 'admin';
}

/**
 * Check if seller is logged in
 */
function isSellerLoggedIn(): bool {
    return getCurrentRole() === 'seller';
}

/**
 * Update activity timestamp for session timeout tracking
 */
function updateActivity(): void {
    startAppSession();
    $_SESSION['last_activity'] = time();
}

// ── Admin Authentication (v1 backward-compatible) ───────────────

/**
 * Attempt admin login — checks database first, falls back to config constants
 *
 * @return bool True if login successful
 */
function attemptAdminLogin(string $username, string $password): bool {
    if (isLoginRateLimited($username)) {
        error_log("Rate limited login attempt for: $username");
        return false;
    }

    // 1. Try database-based admin account first
    $db = getDB();
    $stmt = $db->prepare("SELECT id, password_hash FROM users WHERE username = :u AND role = 'admin' AND is_active = true");
    $stmt->execute([':u' => $username]);
    $dbAdmin = $stmt->fetch();

    if ($dbAdmin && password_verify($password, $dbAdmin['password_hash'])) {
        startAppSession();
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username']  = $username;
        $_SESSION['user_id']         = (int) $dbAdmin['id'];
        $_SESSION['user_role']       = 'admin';
        $_SESSION['last_activity']   = time();

        clearLoginAttempts($username);
        writeAuditLog('admin_login', (int) $dbAdmin['id'], 'admin', $username);
        return true;
    }

    // 2. Fall back to config-based admin (v1 backward compatibility)
    if (defined('ADMIN_USERNAME') && defined('ADMIN_PASSWORD_HASH')) {
        if ($username === ADMIN_USERNAME && password_verify($password, ADMIN_PASSWORD_HASH)) {
            startAppSession();
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username']  = $username;
            $_SESSION['user_role']       = 'admin';
            $_SESSION['last_activity']   = time();

            clearLoginAttempts($username);
            writeAuditLog('admin_login', null, 'admin', $username);
            return true;
        }
    }

    // Failed
    recordLoginAttempt($username);
    error_log("Failed admin login attempt for: $username from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return false;
}

// ── Seller Authentication ───────────────────────────────────────

/**
 * Attempt seller login against the users table
 *
 * @return bool True if login successful
 */
function attemptSellerLogin(string $username, string $password): bool {
    if (isLoginRateLimited($username)) {
        error_log("Rate limited seller login attempt for: $username");
        return false;
    }

    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, password_hash, is_active, full_name
        FROM users
        WHERE username = :u AND role = 'seller'
    ");
    $stmt->execute([':u' => $username]);
    $seller = $stmt->fetch();

    if (!$seller) {
        recordLoginAttempt($username);
        error_log("Failed seller login - not found: $username from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        return false;
    }

    if (!$seller['is_active']) {
        recordLoginAttempt($username);
        error_log("Failed seller login - deactivated: $username from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        return false;
    }

    if (!password_verify($password, $seller['password_hash'])) {
        recordLoginAttempt($username);
        error_log("Failed seller login - bad password: $username from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        return false;
    }

    startAppSession();
    $_SESSION['seller_logged_in'] = true;
    $_SESSION['seller_username']  = $username;
    $_SESSION['seller_full_name'] = $seller['full_name'] ?? $username;
    $_SESSION['user_id']          = (int) $seller['id'];
    $_SESSION['user_role']        = 'seller';
    $_SESSION['last_activity']    = time();

    clearLoginAttempts($username);
    writeAuditLog('seller_login', (int) $seller['id'], 'seller', $username);
    return true;
}

// ── RBAC Middleware ──────────────────────────────────────────────

/**
 * Require any authenticated user (admin or seller)
 */
function requireAuth(): void {
    $role = getCurrentRole();
    if ($role === null) {
        header('Location: /login.php');
        exit;
    }
    updateActivity();
}

/**
 * Require admin role
 */
function requireAdmin(): void {
    if (!isAdminLoggedIn()) {
        header('Location: /login.php');
        exit;
    }
    updateActivity();
}

/**
 * Require seller role (seller-only, NOT admin)
 */
function requireSeller(): void {
    if (!isSellerLoggedIn()) {
        header('Location: /login.php');
        exit;
    }
    updateActivity();
}

/**
 * Require seller OR admin role
 * Admin can access seller pages (full visibility)
 */
function requireSellerOrAdmin(): void {
    $role = getCurrentRole();
    if ($role !== 'admin' && $role !== 'seller') {
        header('Location: /login.php');
        exit;
    }
    updateActivity();
}

/**
 * Check if current user has a specific role
 */
function hasRole(string $role): bool {
    return getCurrentRole() === $role;
}

// ── Logout ──────────────────────────────────────────────────────

/**
 * Logout — destroy session and redirect
 */
function appLogout(): void {
    startAppSession();

    $userId = getCurrentUserId();
    $username = getCurrentUsername();
    $role = getCurrentRole();

    writeAuditLog('logout', $userId, $role, $username);

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
 * Redirect to the appropriate dashboard based on role
 */
function redirectToDashboard(): void {
    $role = getCurrentRole();
    if ($role === 'admin') {
        header('Location: /admin/dashboard.php');
    } elseif ($role === 'seller') {
        header('Location: /seller/dashboard.php');
    } else {
        header('Location: /login.php');
    }
    exit;
}
