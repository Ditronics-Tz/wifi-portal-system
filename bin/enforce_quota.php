#!/usr/bin/env php
<?php
/**
 * Data quota enforcement — CLI cron script
 * =========================================
 * Checks every active voucher against its package data_quota_mb.
 * When a voucher has exceeded its allowance the script:
 *   - marks the voucher 'expired' in PostgreSQL
 *   - closes all tracked sessions
 *   - inserts Auth-Type=Reject so FreeRADIUS blocks reconnects
 *   - sends a CoA Disconnect packet to the AP (best-effort)
 *   - records a QUOTA_EXCEEDED security event
 *
 * INSTALLATION
 * ─────────────────────────────────────────────────────────────────────
 * 1. Ensure the log directory exists and is writable by www-data:
 *      sudo mkdir -p /var/log/voucher-portal
 *      sudo chown www-data:www-data /var/log/voucher-portal
 *
 * 2. Open the www-data crontab:
 *      sudo -u www-data crontab -e
 *
 * 3. Add the line below (runs every minute):
 *      * * * * * /usr/bin/php /var/www/voucher-portal/bin/enforce_quota.php >> /var/log/voucher-portal/quota.log 2>&1
 *
 * The script is idempotent — running it more frequently is safe.
 * An advisory file lock prevents two concurrent runs from racing.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/config.php';
require_once APP_ROOT . '/src/quota_service.php';

// ── Advisory lock: skip if a previous run is still executing ──────────────
$lockFile = sys_get_temp_dir() . '/voucher_quota_enforce.lock';
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    // Another instance is running — exit silently (not an error)
    exit(0);
}

// ── Run ───────────────────────────────────────────────────────────────────
$start = microtime(true);

printf("%s quota-enforce: starting\n", date('[Y-m-d H:i:s]'));

try {
    $result  = runQuotaEnforcement();
    $elapsed = round(microtime(true) - $start, 3);

    printf(
        "%s quota-enforce: done  checked=%d  expired=%d  errors=%d  time=%ss\n",
        date('[Y-m-d H:i:s]'),
        $result['checked'],
        $result['expired'],
        $result['errors'],
        $elapsed
    );

    flock($lock, LOCK_UN);
    fclose($lock);
    exit($result['errors'] > 0 ? 1 : 0);

} catch (Exception $e) {
    printf("%s quota-enforce: FATAL %s\n", date('[Y-m-d H:i:s]'), $e->getMessage());
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(1);
}
