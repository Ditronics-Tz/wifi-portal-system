<?php

// Copy to config.php and replace all placeholder values. Never commit config.php.
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 5432);
define('DB_NAME', 'radius');
define('DB_USER', 'radius');
define('DB_PASS', 'replace-with-database-password');

define('RADIUS_HOST', '127.0.0.1');
define('RADIUS_AUTH_PORT', 1812);
define('RADIUS_SECRET', 'replace-with-radius-shared-secret');
define('RADIUS_COA_PORT', 3799);
define('RADIUS_NAS_IP', '192.168.100.133');
define('RADIUS_NAS_SECRET', 'replace-with-ap-radius-secret');

define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD_HASH', 'replace-with-argon2id-password-hash');

// Session configuration
define('SESSION_NAME', 'voucher_portal');
define('SESSION_LIFETIME', 3600);

// Set to true only once this site is served over HTTPS.
define('SESSION_COOKIE_SECURE', false);

// Security settings
define('VOUCHER_CODE_PATTERN', '/^[0-9]{10}$/');
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900);

// Seller defaults
define('SELLER_MAX_GENERATE_QUANTITY', 100);

// Plan definitions
define('PLANS', [
    'siku_1' => ['name' => 'Siku 1', 'duration_seconds' => 86400, 'price' => 500],
    'wiki_1' => ['name' => 'Wiki 1', 'duration_seconds' => 604800, 'price' => 3000],
    'mwezi_1' => ['name' => 'Mwezi 1', 'duration_seconds' => 2592000, 'price' => 10000],
]);
