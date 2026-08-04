<?php
/**
 * Admin login page — Redirects to universal login
 * Kept for backward compatibility
 */

require_once dirname(__DIR__, 2) . '/src/auth.php';

startAppSession();

if (isAdminLoggedIn()) {
    header('Location: /admin/dashboard.php');
    exit;
}

// Redirect to universal login
header('Location: /login.php');
exit;
