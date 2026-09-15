<?php

declare(strict_types=1);

use App\Http\JsonResponse;
use App\Routes\RouteNames;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return static function (App $app): void {
    $app->get(RouteNames::API_HEALTH, static function (Request $request, Response $response): Response {
        return JsonResponse::write($response, [
            'ok' => true,
            'service' => 'stamp-rally-api',
        ]);
    });

    $app->get(RouteNames::STAMP_RALLY_HEALTH, static function (Request $request, Response $response): Response {
        return JsonResponse::write($response, [
            'ok' => true,
            'feature' => 'stamp-rally',
        ]);
    });
};
