<?php
/**
 * FreeRADIUS client wrapper using proc_open for security
 * Uses stdin instead of shell string to avoid injection
 */

require_once dirname(__DIR__) . '/config.php';

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
 * Send CoA Disconnect-Request (optional/stretch goal)
 * This can be used to forcibly disconnect a user
 */
function radius_disconnect($username, $nasIpAddress = '127.0.0.1', $nasPort = 1) {
    $input = sprintf(
        "User-Name = \"%s\"\nNAS-IP-Address = %s\nNAS-Port = %d\n",
        $username,
        $nasIpAddress,
        $nasPort
    );
    
    // Note: CoA typically uses port 3799, but this depends on FreeRADIUS config
    // This is a stretch goal - implement only if needed
    error_log("CoA disconnect requested for $username - not implemented in v1");
    
    return [
        'success' => false,
        'message' => 'CoA disconnect not implemented in v1'
    ];
}
