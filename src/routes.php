<?php

declare(strict_types=1);

use App\Http\JsonResponse;
use App\Routes\RouteNames;
use App\Services\Auth\LineUserIdResolver;
use App\Services\Exment\ExmentApiClient;
use App\Services\Exment\StampRallyRecordRepository;
use App\Services\Line\LineAuthClient;
use App\Services\Line\LineLoginUrlBuilder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return static function (App $app): void {
    $toPublicPath = static function (string $path, string $basePath): string {
        $normalizedBasePath = trim($basePath);
        if ($normalizedBasePath === '' || $normalizedBasePath === '/') {
            return $path;
        }

        $normalizedBasePath = '/' . trim($normalizedBasePath, '/');
        $normalizedPath = '/' . ltrim($path, '/');

        return $normalizedBasePath . $normalizedPath;
    };

    $writePrivateJson = static function (Response $response, array $payload, int $status = 200): Response {
        return JsonResponse::write($response, $payload, $status)
            ->withHeader('Cache-Control', 'private, no-store');
    };

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

    $app->get(RouteNames::STAMP_RALLY_INIT, static function (Request $request, Response $response) use ($app, $toPublicPath, $writePrivateJson): Response {
        $config = $app->getContainer()?->get('config') ?? \App\Config\AppConfig::fromEnv();
        $userId = (new LineUserIdResolver())->resolve($request);

        if ($userId === null) {
            $loginPath = $toPublicPath(RouteNames::LINE_LOGIN_START, $config->basePath);

            return $writePrivateJson($response, [
                'ok' => false,
                'requiresLogin' => true,
                'loginUrl' => $config->publicSiteUrl === '' ? $loginPath : $config->publicSiteUrl . $loginPath,
            ], 401);
        }

        if (!$config->exment->isConfigured()) {
            return $writePrivateJson($response, [
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
            return $writePrivateJson($response, [
                'ok' => false,
                'error' => 'exment_request_failed',
                'message' => $config->debug ? $exception->getMessage() : 'Failed to initialize stamp rally record.',
            ], 502);
        }

        return $writePrivateJson($response, [
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

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $_SESSION['line_login_state'] = $state;
        $_SESSION['line_login_nonce'] = $nonce;
        $loginUrl = (new LineLoginUrlBuilder($config->line))->build($state, $nonce);

        return $response
            ->withHeader('Location', $loginUrl)
            ->withStatus(302);
    });

    $app->get(RouteNames::LINE_LOGIN_CALLBACK, static function (Request $request, Response $response) use ($app, $toPublicPath): Response {
        $config = $app->getContainer()?->get('config') ?? \App\Config\AppConfig::fromEnv();

        if (!$config->line->isConfigured()) {
            return JsonResponse::write($response, [
                'ok' => false,
                'error' => 'line_not_configured',
            ], 503);
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $query = $request->getQueryParams();
        $state = trim((string) ($query['state'] ?? ''));
        $code = trim((string) ($query['code'] ?? ''));
        $sessionState = trim((string) ($_SESSION['line_login_state'] ?? ''));
        $sessionNonce = trim((string) ($_SESSION['line_login_nonce'] ?? ''));

        if ($state === '' || $sessionState === '' || !hash_equals($sessionState, $state)) {
            return JsonResponse::write($response, [
                'ok' => false,
                'error' => 'invalid_line_state',
            ], 400);
        }

        if ($code === '') {
            return JsonResponse::write($response, [
                'ok' => false,
                'error' => 'missing_line_code',
            ], 400);
        }

        if ($sessionNonce === '') {
            return JsonResponse::write($response, [
                'ok' => false,
                'error' => 'missing_line_nonce',
            ], 400);
        }

        try {
            $userId = (new LineAuthClient($config->line))->fetchUserIdFromAuthorizationCode($code, $sessionNonce);
        } catch (\Throwable $exception) {
            return JsonResponse::write($response, [
                'ok' => false,
                'error' => 'line_auth_failed',
                'message' => $config->debug ? $exception->getMessage() : 'Failed to authenticate with LINE.',
            ], 502);
        }

        session_regenerate_id(true);
        unset($_SESSION['line_login_state']);
        unset($_SESSION['line_login_nonce']);
        $_SESSION['line_user_id'] = $userId;

        return $response
            ->withHeader('Location', $toPublicPath('/', $config->basePath))
            ->withStatus(302);
    });
};
