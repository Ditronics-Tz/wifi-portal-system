#!/usr/bin/env php
<?php
/**
 * Quota accounting hook — real-time per-session cutoff.
 * =====================================================
 * Invoked by FreeRADIUS from the `accounting {}` section via the `quota_hook`
 * exec module (see nginx/freeradius-accounting-quota) on every
 * Interim-Update and Stop. Checks ONE voucher and expires it immediately
 * when its lifetime bytes exceed the package cap, instead of waiting for
 * the bin/enforce_quota.php cron sweep.
 *
 * Usage (called by FreeRADIUS, not by hand):
 *   php bin/quota_accounting_hook.php USERNAME [ACCT_STATUS_TYPE]
 *
 * Safe to run concurrently with the cron: expireVoucherDueToQuota() is
 * guarded by status='active', so the second caller is a no-op. No global
 * lock is taken here on purpose — the hook must never wait behind a cron run.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config.php';
require_once APP_ROOT . '/src/quota_service.php';

$code = $argv[1] ?? '';
$code = strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $code)));
if ($code === '') {
    exit(0);
}

$result = checkSingleVoucherQuota($code);
printf(
    "%s quota-hook: user=%s checked=%d exceeded=%d used=%d quota=%d\n",
    date('[Y-m-d H:i:s]'),
    $code,
    $result['checked'] ? 1 : 0,
    $result['exceeded'] ? 1 : 0,
    $result['used_bytes'],
    $result['quota_bytes']
);
exit(0);
