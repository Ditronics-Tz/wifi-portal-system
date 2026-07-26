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

define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD_HASH', 'replace-with-argon2id-password-hash');
