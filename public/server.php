<?php

declare(strict_types=1);

use App\Bootstrap\AppFactory;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $projectRoot = realpath(dirname(__DIR__));
    $targetPath = $requestPath === '/' ? '/index.html' : $requestPath;
    $resolvedPath = $projectRoot === false
        ? false
        : realpath($projectRoot . DIRECTORY_SEPARATOR . ltrim($targetPath, '/'));
    $isFrontendAsset = $targetPath === '/index.html'
        || str_starts_with($targetPath, '/css/')
        || str_starts_with($targetPath, '/js/')
        || str_starts_with($targetPath, '/json/')
        || str_starts_with($targetPath, '/img/');

    if (
        $isFrontendAsset
        && $projectRoot !== false
        && $resolvedPath !== false
        && is_file($resolvedPath)
        && str_starts_with($resolvedPath, $projectRoot . DIRECTORY_SEPARATOR)
    ) {
        $extension = strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION));
        $contentTypes = [
            'css' => 'text/css',
            'html' => 'text/html; charset=UTF-8',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
        ];

        if (isset($contentTypes[$extension])) {
            header('Content-Type: ' . $contentTypes[$extension]);
        }

        readfile($resolvedPath);
        return;
    }
}

if (file_exists(__DIR__ . '/../.env')) {
    Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

$app = AppFactory::create();
(require __DIR__ . '/../src/routes.php')($app);

$app->run();
