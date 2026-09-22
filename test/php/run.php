<?php

declare(strict_types=1);

use App\Config\AppConfig;
use App\Routes\RouteNames;
use App\Services\Auth\LineUserIdResolver;
use App\Services\Exment\ExmentApiClient;
use App\Services\Exment\ExmentClientInterface;
use App\Services\Exment\HttpResponse;
use App\Services\Exment\HttpTransportInterface;
use App\Services\Exment\StampRallyRecordRepository;
use App\Services\Line\LineAuthClient;
use App\Services\Line\LineLoginUrlBuilder;
use Psr\Container\ContainerInterface;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

require __DIR__ . '/../../vendor/autoload.php';

final class FakeExmentClient implements ExmentClientInterface
{
    /**
     * @param list<array<string, mixed>> $getResults
     */
    public function __construct(
        private array $getResults = [],
        private readonly array $postResult = [],
        private readonly array $putResult = [],
    ) {
    }

    /** @var list<array{method: string, path: string, query?: array<string, mixed>, payload?: array<string, mixed>}> */
    public array $calls = [];

    public function get(string $path, array $query = []): array
    {
        $this->calls[] = [
            'method' => 'GET',
            'path' => $path,
            'query' => $query,
        ];

        return array_shift($this->getResults) ?? ['data' => []];
    }

    public function post(string $path, array $payload): array
    {
        $this->calls[] = [
            'method' => 'POST',
            'path' => $path,
            'payload' => $payload,
        ];

        return $this->postResult;
    }

    public function put(string $path, array $payload): array
    {
        $this->calls[] = [
            'method' => 'PUT',
            'path' => $path,
            'payload' => $payload,
        ];

        return $this->putResult;
    }
}

final class FakeHttpTransport implements HttpTransportInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string|null}> */
    public array $requests = [];
    private int $requestCount = 0;

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requestCount++;
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];

        if ($this->requestCount === 1) {
            return new HttpResponse(200, json_encode([
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'access_token' => 'issued-access-token',
            ], JSON_THROW_ON_ERROR));
        }

        return new HttpResponse(200, json_encode(['data' => []], JSON_THROW_ON_ERROR));
    }
}

final class FakeLineTransport implements HttpTransportInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string|null}> */
    public array $requests = [];

    /** @param list<HttpResponse> $responses */
    public function __construct(private array $responses)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];

        return array_shift($this->responses) ?? new HttpResponse(500, '{}');
    }
}

final class ArrayContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $entries
     */
    public function __construct(private array $entries)
    {
    }

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new RuntimeException("Missing container entry: {$id}");
        }

        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}

$failures = 0;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    global $failures;

    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
        fwrite(STDERR, '  expected: ' . var_export($expected, true) . "\n");
        fwrite(STDERR, '  actual:   ' . var_export($actual, true) . "\n");
    }
}

function assertTrueValue(bool $actual, string $message): void
{
    assertSameValue(true, $actual, $message);
}

function assertFalseValue(bool $actual, string $message): void
{
    assertSameValue(false, $actual, $message);
}

function setLineUserIdForSession(?string $lineUserId): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if ($lineUserId === null) {
        unset($_SESSION['line_user_id']);
        return;
    }

    $_SESSION['line_user_id'] = $lineUserId;
}

function createRallyAppWithConfig(AppConfig $config, ?StampRallyRecordRepository $repository = null): \Slim\App
{
    $entries = ['config' => $config];
    if ($repository !== null) {
        $entries['stamp_rally_repository'] = $repository;
    }

    SlimAppFactory::setContainer(new ArrayContainer($entries));
    $app = \App\Bootstrap\AppFactory::create();
    (require __DIR__ . '/../../src/routes.php')($app);

    return $app;
}

$config = AppConfig::fromEnv([
    'APP_ENV' => 'local',
    'APP_DEBUG' => 'true',
    'APP_BASE_PATH' => '/rally',
    'NUXT_CHANNEL_ID' => 'line-channel',
    'NUXT_CHANNEL_SECRET' => 'line-secret',
    'NUXT_PUBLIC_SITE_URL' => 'https://example.test',
    'EXMENT_BASE_URL' => 'https://exment.example.test/',
    'EXMENT_API_KEY' => 'exment-api-key',
    'EXMENT_CLIENT_ID' => 'exment-client',
    'EXMENT_CLIENT_SECRET' => 'exment-secret',
    'EXMENT_STAMP_RALLY_TABLE' => 'stamp_rally_records',
]);

assertSameValue('local', $config->env, 'APP_ENV is loaded');
assertTrueValue($config->debug, 'APP_DEBUG true string becomes true');
assertSameValue('/rally', $config->basePath, 'APP_BASE_PATH is loaded');
assertSameValue('https://example.test', $config->publicSiteUrl, 'Public site URL is loaded');
assertTrueValue($config->line->isConfigured(), 'LINE config reports configured');
assertSameValue('https://example.test/auth/line/callback', $config->line->redirectUri, 'LINE redirect URI is derived from site URL');
assertTrueValue($config->exment->isConfigured(), 'Exment config reports configured');
assertSameValue('https://exment.example.test', $config->exment->baseUrl, 'Exment base URL trims trailing slash');
assertSameValue('exment-api-key', $config->exment->apiKey, 'Exment API key is loaded');
assertSameValue('stamp_rally_records', $config->exment->stampRallyTable, 'Exment table name is loaded');

$missingApiKeyConfig = AppConfig::fromEnv([
    'EXMENT_BASE_URL' => 'https://exment.example.test',
    'EXMENT_API_KEY' => '',
    'EXMENT_CLIENT_ID' => 'exment-client',
    'EXMENT_CLIENT_SECRET' => 'exment-secret',
]);
assertFalseValue($missingApiKeyConfig->exment->isConfigured(), 'Exment config requires API key');

$defaultConfig = AppConfig::fromEnv(['APP_ENV' => null]);
assertSameValue('production', $defaultConfig->env, 'APP_ENV defaults to production');
assertFalseValue($defaultConfig->debug, 'APP_DEBUG defaults to false');
assertFalseValue($defaultConfig->line->isConfigured(), 'LINE config reports missing values');
assertFalseValue($defaultConfig->exment->isConfigured(), 'Exment config reports missing values');
assertSameValue('digital_stamp_rally', $defaultConfig->exment->stampRallyTable, 'Exment table defaults to digital_stamp_rally');

assertSameValue('/api/health', RouteNames::API_HEALTH, 'API health route is stable');
assertSameValue('/api/stamp-rally/health', RouteNames::STAMP_RALLY_HEALTH, 'Stamp rally health route is stable');
assertSameValue('/api/stamp-rally/init', RouteNames::STAMP_RALLY_INIT, 'Stamp rally init route is reserved');
assertSameValue('/api/stamp-rally/choice', RouteNames::STAMP_RALLY_CHOICE, 'Stamp rally choice route is reserved');
assertSameValue('/api/stamp-rally/ending', RouteNames::STAMP_RALLY_ENDING, 'Stamp rally ending route is reserved');
assertSameValue('/auth/line/start', RouteNames::LINE_LOGIN_START, 'LINE login start route is reserved');
assertSameValue('/auth/line/callback', RouteNames::LINE_LOGIN_CALLBACK, 'LINE login callback route is reserved');

$loginUrl = (new LineLoginUrlBuilder($config->line))->build('state-123', 'nonce-456');
assertTrueValue(str_starts_with($loginUrl, 'https://access.line.me/oauth2/v2.1/authorize?'), 'LINE login URL uses LINE authorize endpoint');
assertTrueValue(str_contains($loginUrl, 'client_id=line-channel'), 'LINE login URL includes client ID');
assertTrueValue(str_contains($loginUrl, 'state=state-123'), 'LINE login URL includes state');

$lineTransport = new FakeLineTransport([
    new HttpResponse(200, json_encode(['id_token' => 'line-id-token'], JSON_THROW_ON_ERROR)),
    new HttpResponse(200, json_encode(['sub' => 'U-line-123'], JSON_THROW_ON_ERROR)),
]);
$lineUserId = (new LineAuthClient($config->line, $lineTransport))->fetchUserIdFromAuthorizationCode('auth-code-123', 'nonce-456');
assertSameValue('U-line-123', $lineUserId, 'LINE auth client resolves user ID from callback code');
assertSameValue('https://api.line.me/oauth2/v2.1/token', $lineTransport->requests[0]['url'], 'LINE auth client calls token endpoint');
assertSameValue('https://api.line.me/oauth2/v2.1/verify', $lineTransport->requests[1]['url'], 'LINE auth client calls verify endpoint');
assertSameValue('id_token=line-id-token&client_id=line-channel&nonce=nonce-456', $lineTransport->requests[1]['body'], 'LINE auth client verifies ID token with nonce');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['line_user_id'] = ' U-session-123 ';
$sessionRequest = (new ServerRequestFactory())->createServerRequest('GET', RouteNames::STAMP_RALLY_INIT);
assertSameValue('U-session-123', (new LineUserIdResolver())->resolve($sessionRequest), 'LINE user ID resolver reads user ID from server session');
unset($_SESSION['line_user_id']);

$transport = new FakeHttpTransport();
$apiClient = new ExmentApiClient($config->exment, $transport);
$apiClient->get('/api/data/stamp_rally_records/query-column', [
    'q' => 'LINE_ID eq U123',
    'count' => 1,
]);
$apiClient->put('/api/data/stamp_rally_records/10', [
    'value' => [
        'loop1_choices' => 'A,B',
    ],
]);
assertSameValue('https://exment.example.test/oauth/token', $transport->requests[0]['url'], 'Exment API client requests an access token');
assertSameValue('grant_type=api_key&client_id=exment-client&client_secret=exment-secret&api_key=exment-api-key&scope=value_read+value_write', $transport->requests[0]['body'], 'Exment API client uses API key grant');
assertSameValue('Bearer issued-access-token', $transport->requests[1]['headers']['Authorization'], 'Exment API client uses issued access token as bearer token');
assertSameValue('https://exment.example.test/api/data/stamp_rally_records/query-column?q=LINE_ID+eq+U123&count=1', $transport->requests[1]['url'], 'Exment API client builds query URL');
assertSameValue('PUT', $transport->requests[2]['method'], 'Exment API client supports PUT requests');
assertSameValue('https://exment.example.test/api/data/stamp_rally_records/10', $transport->requests[2]['url'], 'Exment API client builds update URL');
assertSameValue('{"value":{"loop1_choices":"A,B"}}', $transport->requests[2]['body'], 'Exment API client encodes update payload');

$existingClient = new FakeExmentClient([
    [
        'data' => [
            [
                'id' => 10,
                'value' => [
                    'LINE_ID' => 'U123',
                    'loop_count' => 2,
                ],
            ],
        ],
    ],
]);
$existingRepository = new StampRallyRecordRepository($existingClient, 'stamp_rally_records');
$existingResult = $existingRepository->findOrCreateByLineId('U123');
assertFalseValue($existingResult['created'], 'Repository returns existing record without creating');
assertSameValue(10, $existingResult['record']['id'], 'Repository returns the matched Exment record');
assertSameValue([
    [
        'method' => 'GET',
        'path' => '/api/data/stamp_rally_records/query-column',
        'query' => [
            'q' => 'LINE_ID eq U123',
            'count' => 1,
        ],
    ],
], $existingClient->calls, 'Repository searches Exment by LINE_ID');

$creatingClient = new FakeExmentClient(
    [['data' => []]],
    [
        'id' => 11,
        'value' => [
            'LINE_ID' => 'U999',
            'loop_count' => 1,
        ],
    ],
);
$creatingRepository = new StampRallyRecordRepository($creatingClient, 'stamp_rally_records');
$createdResult = $creatingRepository->findOrCreateByLineId('U999');
assertTrueValue($createdResult['created'], 'Repository creates a record when Exment has no match');
assertSameValue([
    [
        'method' => 'GET',
        'path' => '/api/data/stamp_rally_records/query-column',
        'query' => [
            'q' => 'LINE_ID eq U999',
            'count' => 1,
        ],
    ],
    [
        'method' => 'POST',
        'path' => '/api/data/stamp_rally_records',
        'payload' => [
            'value' => [
                'LINE_ID' => 'U999',
                'loop_count' => 1,
            ],
        ],
    ],
], $creatingClient->calls, 'Repository creates Exment record with LINE_ID and initial loop count');

$choiceClient = new FakeExmentClient(
    [
        [
            'data' => [
                [
                    'id' => 12,
                    'value' => [
                        'LINE_ID' => 'U456',
                        'loop_count' => '2',
                    ],
                ],
            ],
        ],
    ],
    [],
    [
        'id' => 12,
        'value' => [
            'LINE_ID' => 'U456',
            'loop_count' => '2',
            'loop2_choices' => 'B,A',
        ],
    ],
);
$choiceRepository = new StampRallyRecordRepository($choiceClient, 'stamp_rally_records');
$choiceResult = $choiceRepository->saveChoicesByLineId('U456', ['B', 'A']);
assertSameValue('loop2_choices', $choiceResult['column'], 'Repository saves choices to the loop-specific column');
assertSameValue(['B', 'A'], $choiceResult['choices'], 'Repository returns normalized saved choices');
assertSameValue([
    [
        'method' => 'GET',
        'path' => '/api/data/stamp_rally_records/query-column',
        'query' => [
            'q' => 'LINE_ID eq U456',
            'count' => 1,
        ],
    ],
    [
        'method' => 'PUT',
        'path' => '/api/data/stamp_rally_records/12',
        'payload' => [
            'value' => [
                'loop2_choices' => 'B,A',
            ],
        ],
    ],
], $choiceClient->calls, 'Repository updates Exment choices with PUT');

$app = createRallyAppWithConfig(AppConfig::fromEnv());
$request = (new ServerRequestFactory())->createServerRequest('GET', RouteNames::STAMP_RALLY_INIT);
$response = $app->handle($request);
$payload = json_decode((string) $response->getBody(), true);

assertSameValue(401, $response->getStatusCode(), 'Stamp rally init requires login when session user ID is missing');
assertSameValue([
    'ok' => false,
    'requiresLogin' => true,
    'loginUrl' => AppConfig::fromEnv()->publicSiteUrl . (AppConfig::fromEnv()->basePath === '' ? RouteNames::LINE_LOGIN_START : AppConfig::fromEnv()->basePath . RouteNames::LINE_LOGIN_START),
], $payload, 'Stamp rally init returns LINE login instruction');
assertSameValue('private, no-store', $response->getHeaderLine('Cache-Control'), 'Stamp rally init login response is non-cacheable');

$callbackRequest = (new ServerRequestFactory())->createServerRequest('GET', RouteNames::LINE_LOGIN_CALLBACK);
$callbackResponse = $app->handle($callbackRequest);
assertTrueValue($callbackResponse->getStatusCode() !== 404, 'LINE callback route is registered');

$configuredChoiceConfig = AppConfig::fromEnv([
    'APP_ENV' => 'local',
    'APP_DEBUG' => 'true',
    'NUXT_PUBLIC_SITE_URL' => 'https://example.test',
    'EXMENT_BASE_URL' => 'https://exment.example.test',
    'EXMENT_API_KEY' => 'exment-api-key',
    'EXMENT_CLIENT_ID' => 'exment-client',
    'EXMENT_CLIENT_SECRET' => 'exment-secret',
    'EXMENT_STAMP_RALLY_TABLE' => 'stamp_rally_records',
]);

$choiceRouteRepositoryClient = new FakeExmentClient(
    [[
        'data' => [[
            'id' => 44,
            'value' => [
                'LINE_ID' => 'U-route-1',
                'loop_count' => 1,
            ],
        ]],
    ]],
    [],
    [
        'id' => 44,
        'value' => [
            'LINE_ID' => 'U-route-1',
            'loop_count' => 1,
            'loop1_choices' => 'A,B',
        ],
    ],
);
$choiceRouteRepository = new StampRallyRecordRepository($choiceRouteRepositoryClient, 'stamp_rally_records');
$choiceRouteApp = createRallyAppWithConfig($configuredChoiceConfig, $choiceRouteRepository);

setLineUserIdForSession(null);
$unauthorizedChoiceRequest = (new ServerRequestFactory())->createServerRequest('POST', RouteNames::STAMP_RALLY_CHOICE)
    ->withParsedBody(['choices' => ['A']]);
$unauthorizedChoiceResponse = $choiceRouteApp->handle($unauthorizedChoiceRequest);
$unauthorizedChoicePayload = json_decode((string) $unauthorizedChoiceResponse->getBody(), true);
assertSameValue(401, $unauthorizedChoiceResponse->getStatusCode(), 'Stamp rally choice route returns 401 when session user is missing');
assertSameValue(['ok' => false, 'requiresLogin' => true], $unauthorizedChoicePayload, 'Stamp rally choice route returns login-required payload');

setLineUserIdForSession('U-route-1');
$csrfRejectedRequest = (new ServerRequestFactory())->createServerRequest('POST', RouteNames::STAMP_RALLY_CHOICE)
    ->withParsedBody(['choices' => ['A']]);
$csrfRejectedResponse = $choiceRouteApp->handle($csrfRejectedRequest);
$csrfRejectedPayload = json_decode((string) $csrfRejectedResponse->getBody(), true);
assertSameValue(403, $csrfRejectedResponse->getStatusCode(), 'Stamp rally choice route rejects state-changing requests without same-origin headers');
assertSameValue(['ok' => false, 'error' => 'invalid_origin'], $csrfRejectedPayload, 'Stamp rally choice route reports invalid origin');

$invalidChoicesRequest = (new ServerRequestFactory())->createServerRequest('POST', RouteNames::STAMP_RALLY_CHOICE)
    ->withHeader('Origin', 'https://example.test')
    ->withParsedBody(['choices' => ['A', '']]);
$invalidChoicesResponse = $choiceRouteApp->handle($invalidChoicesRequest);
$invalidChoicesPayload = json_decode((string) $invalidChoicesResponse->getBody(), true);
assertSameValue(400, $invalidChoicesResponse->getStatusCode(), 'Stamp rally choice route validates choices payload');
assertSameValue(['ok' => false, 'error' => 'invalid_choices'], $invalidChoicesPayload, 'Stamp rally choice route returns invalid_choices payload');

$missingExmentChoiceConfig = AppConfig::fromEnv([
    'APP_ENV' => 'local',
    'APP_DEBUG' => 'true',
    'NUXT_PUBLIC_SITE_URL' => 'https://example.test',
    'EXMENT_BASE_URL' => 'https://exment.example.test',
    'EXMENT_API_KEY' => '',
    'EXMENT_CLIENT_ID' => 'exment-client',
    'EXMENT_CLIENT_SECRET' => 'exment-secret',
]);
$missingExmentChoiceApp = createRallyAppWithConfig($missingExmentChoiceConfig);
$missingExmentRequest = (new ServerRequestFactory())->createServerRequest('POST', RouteNames::STAMP_RALLY_CHOICE)
    ->withHeader('Origin', 'https://example.test')
    ->withParsedBody(['choices' => ['A']]);
$missingExmentResponse = $missingExmentChoiceApp->handle($missingExmentRequest);
$missingExmentPayload = json_decode((string) $missingExmentResponse->getBody(), true);
assertSameValue(503, $missingExmentResponse->getStatusCode(), 'Stamp rally choice route returns 503 when Exment config is missing');
assertSameValue(['ok' => false, 'error' => 'exment_not_configured'], $missingExmentPayload, 'Stamp rally choice route returns exment_not_configured payload');

$successfulChoiceRequest = (new ServerRequestFactory())->createServerRequest('POST', RouteNames::STAMP_RALLY_CHOICE)
    ->withHeader('Origin', 'https://example.test')
    ->withParsedBody(['choices' => ['A', 'B']]);
$successfulChoiceResponse = $choiceRouteApp->handle($successfulChoiceRequest);
$successfulChoicePayload = json_decode((string) $successfulChoiceResponse->getBody(), true);
assertSameValue(200, $successfulChoiceResponse->getStatusCode(), 'Stamp rally choice route saves choices when request is valid');
assertSameValue([
    'ok' => true,
    'column' => 'loop1_choices',
    'choices' => ['A', 'B'],
    'record' => [
        'id' => 44,
        'value' => [
            'LINE_ID' => 'U-route-1',
            'loop_count' => 1,
            'loop1_choices' => 'A,B',
        ],
    ],
], $successfulChoicePayload, 'Stamp rally choice route returns saved choice payload');
assertSameValue([
    [
        'method' => 'GET',
        'path' => '/api/data/stamp_rally_records/query-column',
        'query' => [
            'q' => 'LINE_ID eq U-route-1',
            'count' => 1,
        ],
    ],
    [
        'method' => 'PUT',
        'path' => '/api/data/stamp_rally_records/44',
        'payload' => [
            'value' => [
                'loop1_choices' => 'A,B',
            ],
        ],
    ],
], $choiceRouteRepositoryClient->calls, 'Stamp rally choice route writes choices using repository');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} PHP test assertion(s) failed.\n");
    exit(1);
}

fwrite(STDOUT, "PHP tests passed.\n");
