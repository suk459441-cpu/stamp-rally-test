<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Config\AppConfig;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

final class AppFactory
{
    public static function create(): App
    {
        $config = AppConfig::fromEnv();
        $app = SlimAppFactory::create();

        if ($config->basePath !== '') {
            $app->setBasePath($config->basePath);
        }

        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware($config->debug, true, true);

        return $app;
    }
}
