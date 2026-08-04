<?php
/**
 * Seller Logout
 */

require_once dirname(__DIR__, 2) . '/src/auth.php';

appLogout();

header('Location: /login.php?logout=1');
exit;
