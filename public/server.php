<?php

declare(strict_types=1);

use App\Bootstrap\AppFactory;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

$app = AppFactory::create();
(require __DIR__ . '/../src/routes.php')($app);

$app->run();
