<?php
/**
 * Router with security hardening
 */

// ── Security Headers ─────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; font-src \'self\' https://fonts.gstatic.com; img-src \'self\' data:;');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header('Cross-Origin-Embedder-Policy: require-corp');

// Hide PHP version
header_remove('X-Powered-By');

// ── Request Parsing ──────────────────────────────────────────────
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';
$requestPath = urldecode($requestPath);
$baseDir = __DIR__;

// ── Block Path Traversal (LFI) ───────────────────────────────────
// Block any request containing ../ or ..\ or encoded variants
$dangerous = ['../', '..\\', '%2e%2e', '%2e%2e/', '..%2f', '%2e%2e%2f', '..%5c', '%2e%2e%5c'];
$lowerPath = strtolower($requestPath);
foreach ($dangerous as $pattern) {
    if (strpos($lowerPath, $pattern) !== false) {
        http_response_code(403);
        echo 'Forbidden';
        return true;
    }
}

// Block null bytes
if (strpos($requestPath, "\0") !== false) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// ── Routes (defined early so security checks can reference them) ──
$routes = [
    // Public pages
    '/'                    => $baseDir . '/public/index.php',
    '/index.php'           => $baseDir . '/public/index.php',
    '/status.php'          => $baseDir . '/public/status.php',
    '/login.php'           => $baseDir . '/public/login.php',

    // Admin pages
    '/admin/login.php'     => $baseDir . '/public/admin/login.php',
    '/admin/dashboard.php' => $baseDir . '/public/admin/dashboard.php',
    '/admin/generate.php'  => $baseDir . '/public/admin/generate.php',
    '/admin/sellers.php'   => $baseDir . '/public/admin/sellers.php',
    '/admin/packages.php'  => $baseDir . '/public/admin/packages.php',
    '/admin/analytics.php' => $baseDir . '/public/admin/analytics.php',
    '/admin/logout.php'    => $baseDir . '/public/admin/logout.php',

    // Seller pages
    '/seller/dashboard.php'   => $baseDir . '/public/seller/dashboard.php',
    '/seller/generate.php'    => $baseDir . '/public/seller/generate.php',
    '/seller/record-sale.php' => $baseDir . '/public/seller/record-sale.php',
    '/seller/my-sales.php'    => $baseDir . '/public/seller/my-sales.php',
    '/seller/logout.php'      => $baseDir . '/public/seller/logout.php',
];

// If request matches a known route, serve it directly (skip security block)
if (isset($routes[$requestPath])) {
    require $routes[$requestPath];
    return true;
}

// ── Block Access to Sensitive Files ──────────────────────────────
$sensitivePatterns = [
    '/.git',
    '/.env',
    '/.htaccess',
    '/config.php',
    '/config.example.php',
    '/src/',
    '/migrations/',
    '/data/',
    '/admin/',           // Old v1 admin directory — blocked
    '/deploy.sh',
    '/router.php',
    '/README.md',
    '/wifi_voucher_',
];

foreach ($sensitivePatterns as $pattern) {
    if (stripos($requestPath, $pattern) === 0) {
        http_response_code(403);
        echo 'Forbidden';
        return true;
    }
}

// Block hidden files (starting with .)
$pathParts = explode('/', $requestPath);
foreach ($pathParts as $part) {
    if (!empty($part) && $part[0] === '.' && $part !== '.') {
        http_response_code(403);
        echo 'Forbidden';
        return true;
    }
}

// ── Secure Session Cookies ───────────────────────────────────────
// These will be applied when session_start() is called in auth.php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '0');    // Set to 1 if using HTTPS
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.sid_length', '48');
ini_set('session.sid_bits_per_character', '6');

// ── Serve Static Files (only from /public/) ─────────────────────
$allowedStaticDir = $baseDir . '/public';
$filePath = $allowedStaticDir . $requestPath;

// Normalize path and verify it's inside allowed directory
$realPath = realpath($filePath);
$realAllowedDir = realpath($allowedStaticDir);

if ($realPath && $realAllowedDir && strpos($realPath, $realAllowedDir) === 0 && is_file($realPath)) {
    $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
    $contentTypes = [
        'css'   => 'text/css; charset=UTF-8',
        'js'    => 'application/javascript; charset=UTF-8',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'eot'   => 'application/vnd.ms-fontobject',
    ];

    if (isset($contentTypes[$extension])) {
        header('Content-Type: ' . $contentTypes[$extension]);
        header('Cache-Control: public, max-age=86400');
        readfile($realPath);
        return true;
    }
}

// ── 404 ──────────────────────────────────────────────────────────
http_response_code(404);
echo 'Not Found';
return true;
