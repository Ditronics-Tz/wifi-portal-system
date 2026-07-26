<?php

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$requestPath = urldecode($requestPath);
$baseDir = __DIR__;

$routes = [
    '/' => $baseDir . '/public/index.php',
    '/index.php' => $baseDir . '/public/index.php',
    '/status.php' => $baseDir . '/public/status.php',
];

if (isset($routes[$requestPath])) {
    require $routes[$requestPath];
    return true;
}

$publicFile = $baseDir . '/public' . $requestPath;
$projectFile = $baseDir . $requestPath;

function serveStaticFile($filePath) {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $contentTypes = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'html' => 'text/html; charset=UTF-8',
    ];

    if (isset($contentTypes[$extension])) {
        header('Content-Type: ' . $contentTypes[$extension]);
    }

    readfile($filePath);
}

foreach ([$publicFile, $projectFile] as $filePath) {
    if (is_file($filePath)) {
        if (pathinfo($filePath, PATHINFO_EXTENSION) === 'php') {
            require $filePath;
        } else {
            serveStaticFile($filePath);
        }
        return true;
    }
}

http_response_code(404);
echo 'Not Found';
return true;