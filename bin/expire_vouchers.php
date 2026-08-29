#!/usr/bin/env php
<?php
/**
 * Voucher time-expiry cron script
 * =================================
 * Sweeps the database every minute for active vouchers whose expires_at
 * has passed and marks them 'expired'.
 *
 * WHY: FreeRADIUS stops accepting connections once Session-Timeout runs out,
 * but the DB status column stays 'active' until this script runs.  Without
 * it, the admin panel and status page show stale "Active" entries forever.
 *
 * INSTALL:
 *   (crontab -l 2>/dev/null; echo "* * * * * /usr/bin/php /var/www/voucher-portal/bin/expire_vouchers.php >> /home/ditronics_kibada/logs/voucher-portal/expiry.log 2>&1") | crontab -
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config.php';
require_once APP_ROOT . '/src/voucher_service.php';
require_once APP_ROOT . '/src/session_service.php';

// Advisory lock — skip if another instance still running
$lock = fopen(sys_get_temp_dir() . '/voucher_expiry.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$start = microtime(true);

try {
    $result  = expireOverdueVouchers();
    $elapsed = round(microtime(true) - $start, 3);

    if ($result['expired'] > 0 || $result['errors'] > 0) {
        printf(
            "%s expire-vouchers: expired=%d errors=%d time=%ss\n",
            date('[Y-m-d H:i:s]'),
            $result['expired'],
            $result['errors'],
            $elapsed
        );
    }
    // Silent when nothing to do (avoids noisy logs)

    flock($lock, LOCK_UN);
    fclose($lock);
    exit($result['errors'] > 0 ? 1 : 0);

} catch (Exception $e) {
    printf("%s expire-vouchers: FATAL %s\n", date('[Y-m-d H:i:s]'), $e->getMessage());
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(1);
}
