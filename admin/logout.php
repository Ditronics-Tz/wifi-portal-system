<?php
/**
 * Admin logout
 */

require_once dirname(__DIR__) . '/src/auth.php';

adminLogout();

header('Location: login.php?logout=1');
exit;
