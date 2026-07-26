<?php
/**
 * Admin logout
 */

require_once '/var/www/voucher-portal/src/auth.php';

adminLogout();

header('Location: login.php?logout=1');
exit;
