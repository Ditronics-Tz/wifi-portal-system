<?php
/**
 * FreeRADIUS client wrapper using proc_open for security
 * Uses stdin instead of shell string to avoid injection
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/db.php';

/**
 * Send Access-Request to FreeRADIUS via radclient
 * 
 * @param string $username Voucher code (username)
 * @param string $password Voucher code (used as password)
 * @return array ['success' => bool, 'message' => string, 'attributes' => array]
 */
function radius_authenticate($username, $password) {
    // Validate inputs - only allow alphanumeric
    if (!preg_match('/^[A-Za-z0-9]{1,64}$/', $username) || 
        !preg_match('/^[A-Za-z0-9]{1,64}$/', $password)) {
        return [
            'success' => false,
            'message' => 'Invalid credentials format',
            'attributes' => []
        ];
    }
    
    // Build the radclient input format: User-Name, User-Password, NAS-IP-Address
    $input = sprintf(
        "User-Name = \"%s\"\nUser-Password = \"%s\"\nNAS-IP-Address = 127.0.0.1\nNAS-Port = 1\nCalled-Station-Id = PHP-Portal\n",
        $username,
        $password
    );
    
    $target = sprintf('%s:%d', RADIUS_HOST, RADIUS_AUTH_PORT);
    
    // Use proc_open for security - no shell string interpolation
    $descriptors = [
        0 => ['pipe', 'r'], // stdin
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w']  // stderr
    ];
    
    $command = [
        'radclient',
        '-t', '5',           // timeout 5 seconds
        '-r', '2',           // retry 2 times
        '-c', '1',           // count 1 attempt
        $target,
        'auth',
        RADIUS_SECRET
    ];
    
    $process = proc_open($command, $descriptors, $pipes);
    
    if (!is_resource($process)) {
        error_log('Failed to start radclient process');
        return [
            'success' => false,
            'message' => 'System unavailable. Please try again later.',
            'attributes' => []
        ];
    }
    
    // Write input to stdin
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    
    // Read output
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    
    $exitCode = proc_close($process);
    
    // Log for debugging (remove in production)
    error_log("radclient exit code: $exitCode");
    error_log("radclient stdout: $stdout");
    if (!empty($stderr)) {
        error_log("radclient stderr: $stderr");
    }
    
    // Parse response
    $attributes = parseRadiusResponse($stdout);
    
    // Check if Access-Accept
    if (strpos($stdout, 'Access-Accept') !== false) {
        return [
            'success' => true,
            'message' => 'Authentication successful',
            'attributes' => $attributes
        ];
    } elseif (strpos($stdout, 'Access-Reject') !== false) {
        return [
            'success' => false,
            'message' => 'Access rejected',
            'attributes' => $attributes
        ];
    } else {
        // Timeout or other error
        return [
            'success' => false,
            'message' => 'System unavailable. Please try again later.',
            'attributes' => []
        ];
    }
}

/**
 * Parse radclient output to extract RADIUS attributes
 */
function parseRadiusResponse($output) {
    $attributes = [];
    
    // Match lines like: Session-Timeout = 86400
    if (preg_match_all('/^(\S+)\s*=\s*(.+)$/m', $output, $matches)) {
        foreach ($matches[1] as $index => $name) {
            $attributes[$name] = trim($matches[2][$index], '"');
        }
    }
    
    return $attributes;
}

/**
 * Send Disconnect-Request to the NAS (AP). EAP firmware may ignore CoA;
 * the voucher is still expired in the database regardless.
 */
function radius_disconnect($username, $nasIpAddress = null, $nasPort = 1) {
    if (!preg_match('/^[A-Za-z0-9]{1,64}$/', $username)) {
        return ['success' => false, 'message' => 'Invalid username'];
    }

    $nasIp = $nasIpAddress ?: (defined('RADIUS_NAS_IP') ? RADIUS_NAS_IP : '127.0.0.1');
    if (!filter_var($nasIp, FILTER_VALIDATE_IP)) {
        return ['success' => false, 'message' => 'Invalid NAS IP'];
    }

    $coaPort = defined('RADIUS_COA_PORT') ? (int) RADIUS_COA_PORT : 3799;
    $secret = defined('RADIUS_NAS_SECRET') ? RADIUS_NAS_SECRET : RADIUS_SECRET;

    $acctSessionId = null;
    $callingStation = null;
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT acctsessionid, callingstationid
            FROM radacct
            WHERE username = :username AND acctstoptime IS NULL
            ORDER BY acctstarttime DESC
            LIMIT 1
        ");
        $stmt->execute([':username' => $username]);
        $acct = $stmt->fetch();
        if ($acct) {
            $acctSessionId = $acct['acctsessionid'] ?: null;
            $callingStation = $acct['callingstationid'] ?: null;
        }
    } catch (Exception $e) {
        // radacct optional
    }

    $lines = [
        sprintf('User-Name = "%s"', $username),
        sprintf('NAS-IP-Address = %s', $nasIp),
        sprintf('NAS-Port = %d', (int) $nasPort),
    ];
    if ($acctSessionId && preg_match('/^[A-Za-z0-9.:_-]+$/', $acctSessionId)) {
        $lines[] = sprintf('Acct-Session-Id = "%s"', $acctSessionId);
    }
    if ($callingStation && preg_match('/^[A-Fa-f0-9:.-]+$/', $callingStation)) {
        $lines[] = sprintf('Calling-Station-Id = "%s"', $callingStation);
    }
    $input = implode("\n", $lines) . "\n";

    $target = sprintf('%s:%d', $nasIp, $coaPort);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $command = ['radclient', '-t', '5', '-r', '2', '-c', '1', $target, 'disconnect', $secret];

    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        error_log('Failed to start radclient for disconnect');
        return ['success' => false, 'message' => 'Disconnect client unavailable'];
    }

    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    error_log("radclient disconnect exit=$exitCode stdout=$stdout stderr=$stderr");

    $ok = (strpos($stdout, 'Disconnect-ACK') !== false) || (strpos($stdout, 'CoA-ACK') !== false);
    return [
        'success' => $ok,
        'message' => $ok ? 'Disconnect sent' : trim($stdout . ' ' . $stderr),
    ];
}
