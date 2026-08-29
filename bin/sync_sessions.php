#!/usr/bin/env php
<?php
/**
 * CLI entrypoint: pull live RADIUS accounting into voucher_sessions and
 * run sharing detection (auto-disconnect / auto-suspend on repeat offense).
 *
 * Run every 1-2 minutes via system cron on the portal host, e.g.:
 *   * * * * * php /path/to/wifi-portal-system/bin/sync_sessions.php >> /var/log/wifi-portal-sync.log 2>&1
 */

require_once dirname(__DIR__) . '/src/session_service.php';

$updated = syncSessionsFromRadacct();
echo date('c') . " synced {$updated} session(s)\n";
