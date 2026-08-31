#!/usr/bin/env php
<?php
/**
 * Report quota-enforcement health: radacct interim updates, sqlcounter, CoA.
 *
 *   php bin/verify_quota_setup.php
 *   php bin/verify_quota_setup.php --coa-test VOUCHER_CODE
 */

if (php_sapi_name() !== 'cli') {
    exit("CLI only.\n");
}

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config.php';
require_once APP_ROOT . '/src/quota_service.php';
require_once APP_ROOT . '/src/radius_client.php';

$coaTestUser = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--coa-test' && isset($argv[$i + 1])) {
        $coaTestUser = preg_replace('/[^A-Z0-9]/', '', $argv[$i + 1]);
    }
}

$db = getDB();
echo "=== Quota enforcement health ===\n\n";

// 1. sqlcounter module file
$modEnabled = is_link('/etc/freeradius/3.0/mods-enabled/sqlcounter_quota')
    || is_file('/etc/freeradius/3.0/mods-enabled/sqlcounter_quota');
echo 'FreeRADIUS sqlcounter_quota enabled: ' . ($modEnabled ? "YES\n" : "NO — run sudo bin/install_freeradius_quota.sh\n");

$siteFile = '/etc/freeradius/3.0/sites-enabled/default';
if (is_readable($siteFile)) {
    $site = file_get_contents($siteFile);
    $inAuth = preg_match('/authorize\s*\{[^}]*sqlcounter_quota/s', $site);
    echo 'sqlcounter_quota in authorize{}: ' . ($inAuth ? "YES\n" : "NO\n");
} else {
    echo "sites-enabled/default: not readable (need root to verify)\n";
}

// 2. Open sessions / interim accounting
try {
    $stmt = $db->query("
        SELECT username, acctstarttime, acctupdatetime,
               EXTRACT(EPOCH FROM (acctupdatetime - acctstarttime)) AS secs_since_start,
               acctinputoctets + acctoutputoctets AS bytes
        FROM radacct
        WHERE acctstoptime IS NULL
        ORDER BY acctupdatetime DESC
        LIMIT 5
    ");
    $open = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "\nOpen radacct sessions: " . count($open) . "\n";
    foreach ($open as $row) {
        $mb = round($row['bytes'] / 1048576, 2);
        $interim = ((float) $row['secs_since_start'] > 45 && (int) $row['bytes'] > 0) ? 'likely OK' : 'check AP interim';
        printf(
            "  %s  start=%s  updated=%s  %.2f MB  interim=%s\n",
            $row['username'],
            $row['acctstarttime'],
            $row['acctupdatetime'],
            $mb,
            $interim
        );
    }
    if (empty($open)) {
        echo "  (none — start a test session to verify interim updates)\n";
    }

    // Last closed session: did bytes grow before stop?
    $stmt = $db->query("
        SELECT username, acctstarttime, acctstoptime,
               acctinputoctets + acctoutputoctets AS bytes
        FROM radacct
        WHERE acctstoptime IS NOT NULL
        ORDER BY acctstoptime DESC
        LIMIT 3
    ");
    echo "\nRecent closed sessions:\n";
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        printf(
            "  %s  %s → %s  %.2f MB\n",
            $row['username'],
            $row['acctstarttime'],
            $row['acctstoptime'],
            $row['bytes'] / 1048576
        );
    }
} catch (Exception $e) {
    echo "radacct error: " . $e->getMessage() . "\n";
}

// 3. CoA port reachability
$nasIp = defined('RADIUS_NAS_IP') ? RADIUS_NAS_IP : '127.0.0.1';
$coaPort = defined('RADIUS_COA_PORT') ? (int) RADIUS_COA_PORT : 3799;
$fp = @fsockopen($nasIp, $coaPort, $errno, $errstr, 2);
echo "\nCoA port {$nasIp}:{$coaPort} reachable: " . ($fp ? "YES\n" : "NO ($errstr)\n");
if ($fp) {
    fclose($fp);
}

if ($coaTestUser) {
    echo "\nCoA disconnect test for {$coaTestUser}:\n";
    $result = radius_disconnect($coaTestUser);
    echo ($result['success'] ? '  ACK' : '  FAIL') . ': ' . $result['message'] . "\n";
}

echo "\nDone.\n";
