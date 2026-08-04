<?php
/**
 * Admin login — redirect to public login
 */
require_once dirname(__DIR__) . '/src/auth.php';
startAppSession();
if (isAdminLoggedIn()) {
    header('Location: /admin/dashboard.php');
} else {
    header('Location: /login.php');
}
exit;
