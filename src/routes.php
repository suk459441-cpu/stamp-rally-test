<?php

declare(strict_types=1);

use App\Http\JsonResponse;
use App\Routes\RouteNames;
use App\Services\Auth\LineUserIdResolver;
use App\Services\Exment\ExmentApiClient;
use App\Services\Exment\StampRallyRecordRepository;
use App\Services\Line\LineLoginUrlBuilder;
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

    $app->get(RouteNames::STAMP_RALLY_INIT, static function (Request $request, Response $response) use ($app): Response {
        $config = $app->getContainer()?->get('config') ?? \App\Config\AppConfig::fromEnv();
        $userId = (new LineUserIdResolver())->resolve($request);

        if ($userId === null) {
            return JsonResponse::write($response, [
                'ok' => false,
                'requiresLogin' => true,
                'loginUrl' => RouteNames::LINE_LOGIN_START,
            ], 401);
        }

        if (!$config->exment->isConfigured()) {
            return JsonResponse::write($response, [
                'ok' => false,
                'error' => 'exment_not_configured',
            ], 503);
        }

        try {
            $repository = new StampRallyRecordRepository(
                new ExmentApiClient($config->exment),
                $config->exment->stampRallyTable,
            );
            $result = $repository->findOrCreateByLineId($userId);
        } catch (\Throwable $exception) {
            return JsonResponse::write($response, [
                'ok' => false,
                'error' => 'exment_request_failed',
                'message' => $config->debug ? $exception->getMessage() : 'Failed to initialize stamp rally record.',
            ], 502);
        }

        return JsonResponse::write($response, [
            'ok' => true,
            'created' => $result['created'],
            'record' => $result['record'],
        ]);
    });

    $app->get(RouteNames::LINE_LOGIN_START, static function (Request $request, Response $response) use ($app): Response {
        $config = $app->getContainer()?->get('config') ?? \App\Config\AppConfig::fromEnv();

        if (!$config->line->isConfigured()) {
            return JsonResponse::write($response, [
                'ok' => false,
                'error' => 'line_not_configured',
            ], 503);
        }

        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $loginUrl = (new LineLoginUrlBuilder($config->line))->build($state, $nonce);

        return $response
            ->withHeader('Location', $loginUrl)
            ->withHeader('Set-Cookie', 'line_login_state=' . rawurlencode($state) . '; Path=/; HttpOnly; SameSite=Lax')
            ->withStatus(302);
    });
};
