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
    'STAMP_TOKEN_1' => 'token-1',
    'STAMP_TOKEN_2' => 'token-2',
    'STAMP_TOKEN_3' => 'token-3',
    'STAMP_TOKEN_4' => 'token-4',
    'STAMP_TOKEN_5' => 'token-5',
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
assertSameValue([
    1 => 'token-1',
    2 => 'token-2',
    3 => 'token-3',
    4 => 'token-4',
    5 => 'token-5',
], $config->stampTokens, 'Stamp QR tokens are loaded');

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
assertSameValue('/api/stamp-rally/stamp', RouteNames::STAMP_RALLY_STAMP, 'Stamp rally stamp route is reserved');
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

$thirdLoopChoiceClient = new FakeExmentClient(
    [
        [
            'data' => [
                [
                    'id' => 13,
                    'value' => [
                        'LINE_ID' => 'U789',
                        'loop_count' => 3,
                    ],
                ],
            ],
        ],
    ],
    [],
    [
        'id' => 13,
        'value' => [
            'LINE_ID' => 'U789',
            'loop_count' => 3,
            'loop3_choices' => 'A,B,A',
        ],
    ],
);
$thirdLoopChoiceRepository = new StampRallyRecordRepository($thirdLoopChoiceClient, 'stamp_rally_records');
$thirdLoopChoiceResult = $thirdLoopChoiceRepository->saveChoicesByLineId('U789', ['A', 'B', 'A']);
assertSameValue('loop3_choices', $thirdLoopChoiceResult['column'], 'Repository saves third-loop choices to loop3_choices');
assertSameValue([
    [
        'method' => 'GET',
        'path' => '/api/data/stamp_rally_records/query-column',
        'query' => [
            'q' => 'LINE_ID eq U789',
            'count' => 1,
        ],
    ],
    [
        'method' => 'PUT',
        'path' => '/api/data/stamp_rally_records/13',
        'payload' => [
            'value' => [
                'loop3_choices' => 'A,B,A',
            ],
        ],
    ],
], $thirdLoopChoiceClient->calls, 'Repository updates third-loop choices with PUT');

$stampClient = new FakeExmentClient(
    [
        [
            'data' => [
                [
                    'id' => 15,
                    'value' => [
                        'LINE_ID' => 'U-stamp-1',
                        'loop_count' => 1,
                        'collected_stamps' => '1,2',
                    ],
                ],
            ],
        ],
    ],
    [],
    [
        'id' => 15,
        'value' => [
            'LINE_ID' => 'U-stamp-1',
            'loop_count' => 1,
            'collected_stamps' => '1,2,3',
        ],
    ],
);
$stampRepository = new StampRallyRecordRepository($stampClient, 'stamp_rally_records');
$stampResult = $stampRepository->acquireStampByLineId('U-stamp-1', 3);
assertSameValue(3, $stampResult['stamp'], 'Repository returns acquired stamp ID');
assertSameValue([1, 2, 3], $stampResult['stamps'], 'Repository returns collected stamps');
assertFalseValue($stampResult['alreadyAcquired'], 'Repository reports newly acquired stamp');
assertSameValue([
    [
        'method' => 'GET',
        'path' => '/api/data/stamp_rally_records/query-column',
        'query' => [
            'q' => 'LINE_ID eq U-stamp-1',
            'count' => 1,
        ],
    ],
    [
        'method' => 'PUT',
        'path' => '/api/data/stamp_rally_records/15',
        'payload' => [
            'value' => [
                'collected_stamps' => '1,2,3',
            ],
        ],
    ],
], $stampClient->calls, 'Repository updates collected stamps with PUT');

$alreadyAcquiredClient = new FakeExmentClient([
    [
        'data' => [
            [
                'id' => 16,
                'value' => [
                    'LINE_ID' => 'U-stamp-2',
                    'collected_stamps' => '1,2',
                ],
            ],
        ],
    ],
]);
$alreadyAcquiredRepository = new StampRallyRecordRepository($alreadyAcquiredClient, 'stamp_rally_records');
$alreadyAcquiredResult = $alreadyAcquiredRepository->acquireStampByLineId('U-stamp-2', 2);
assertTrueValue($alreadyAcquiredResult['alreadyAcquired'], 'Repository treats already collected stamps as success');
assertSameValue([1, 2], $alreadyAcquiredResult['stamps'], 'Repository returns existing collected stamps');
assertSameValue(1, count($alreadyAcquiredClient->calls), 'Repository does not update already collected stamps');

$stuckCompletedLoopClient = new FakeExmentClient(
    [
        [
            'data' => [
                [
                    'id' => 22,
                    'value' => [
                        'LINE_ID' => 'U-stamp-stuck',
                        'loop_count' => 2,
                        'collected_stamps' => '1,2,3,4,5',
                    ],
                ],
            ],
        ],
    ],
    [],
    [
        'id' => 22,
        'value' => [
            'LINE_ID' => 'U-stamp-stuck',
            'loop_count' => 2,
            'collected_stamps' => '1',
        ],
    ],
);
$stuckCompletedLoopRepository = new StampRallyRecordRepository($stuckCompletedLoopClient, 'stamp_rally_records');
$stuckCompletedLoopResult = $stuckCompletedLoopRepository->acquireStampByLineId('U-stamp-stuck', 1);
assertFalseValue($stuckCompletedLoopResult['alreadyAcquired'], 'Repository restarts stuck completed loops from stamp 1');
assertSameValue([1], $stuckCompletedLoopResult['stamps'], 'Repository returns restarted stamp progression');
assertSameValue([
    [
        'method' => 'GET',
        'path' => '/api/data/stamp_rally_records/query-column',
        'query' => [
            'q' => 'LINE_ID eq U-stamp-stuck',
            'count' => 1,
        ],
    ],
    [
        'method' => 'PUT',
        'path' => '/api/data/stamp_rally_records/22',
        'payload' => [
            'value' => [
                'collected_stamps' => '1',
            ],
        ],
    ],
], $stuckCompletedLoopClient->calls, 'Repository rewrites stuck completed loop stamps from stamp 1');

$terminalClearedLoopClient = new FakeExmentClient([
    [
        'data' => [
            [
                'id' => 24,
                'value' => [
                    'LINE_ID' => 'U-stamp-terminal',
                    'loop_count' => 3,
                    'collected_endings' => 'END-AI',
                    'collected_stamps' => '1,2,3,4,5',
                    'cleared_at' => '2026-01-01 00:00:00',
                ],
            ],
        ],
    ],
]);
$terminalClearedLoopRepository = new StampRallyRecordRepository($terminalClearedLoopClient, 'stamp_rally_records');
$terminalClearedLoopResult = $terminalClearedLoopRepository->acquireStampByLineId('U-stamp-terminal', 1);
assertTrueValue($terminalClearedLoopResult['alreadyAcquired'], 'Repository keeps terminal cleared records in already acquired state');
assertSameValue([1, 2, 3, 4, 5], $terminalClearedLoopResult['stamps'], 'Repository preserves terminal cleared stamp progression');
assertSameValue(1, count($terminalClearedLoopClient->calls), 'Repository does not rewrite terminal cleared loop stamps');

$stuckFirstLoopCompletedClient = new FakeExmentClient(
    [
        [
            'data' => [
                [
                    'id' => 23,
                    'value' => [
                        'LINE_ID' => 'U-stamp-stuck-loop1',
                        'loop_count' => 1,
                        'collected_endings' => 'END-01',
                        'collected_stamps' => '1,2,3,4,5',
                    ],
                ],
            ],
        ],
    ],
    [],
    [
        'id' => 23,
        'value' => [
            'LINE_ID' => 'U-stamp-stuck-loop1',
            'loop_count' => 1,
            'collected_endings' => 'END-01',
            'collected_stamps' => '1',
        ],
    ],
);
$stuckFirstLoopCompletedRepository = new StampRallyRecordRepository($stuckFirstLoopCompletedClient, 'stamp_rally_records');
$stuckFirstLoopCompletedResult = $stuckFirstLoopCompletedRepository->acquireStampByLineId('U-stamp-stuck-loop1', 1);
assertFalseValue($stuckFirstLoopCompletedResult['alreadyAcquired'], 'Repository restarts loop-1 records that already reached an ending');
assertSameValue([1], $stuckFirstLoopCompletedResult['stamps'], 'Repository returns restarted loop-1 stamp progression');
assertSameValue([
    [
        'method' => 'GET',
        'path' => '/api/data/stamp_rally_records/query-column',
        'query' => [
            'q' => 'LINE_ID eq U-stamp-stuck-loop1',
            'count' => 1,
        ],
    ],
    [
        'method' => 'PUT',
        'path' => '/api/data/stamp_rally_records/23',
        'payload' => [
            'value' => [
                'collected_stamps' => '1',
            ],
        ],
    ],
], $stuckFirstLoopCompletedClient->calls, 'Repository rewrites loop-1 records that already reached an ending');

$outOfOrderClient = new FakeExmentClient([
    [
        'data' => [
            [
                'id' => 17,
                'value' => [
                    'LINE_ID' => 'U-stamp-3',
                    'collected_stamps' => '1',
                ],
            ],
        ],
    ],
]);
$outOfOrderRepository = new StampRallyRecordRepository($outOfOrderClient, 'stamp_rally_records');
try {
    $outOfOrderRepository->acquireStampByLineId('U-stamp-3', 3);
    assertTrueValue(false, 'Repository rejects out-of-order stamps');
} catch (DomainException $exception) {
    assertSameValue('out_of_order_stamp', $exception->getMessage(), 'Repository reports out-of-order stamps');
}

$endingClient = new FakeExmentClient(
    [
        [
            'data' => [
                [
                    'id' => 18,
                    'value' => [
                        'LINE_ID' => 'U-ending-1',
                        'loop_count' => 1,
                        'collected_endings' => 'END-01',
                        'collected_stamps' => '1,2,3,4,5',
                    ],
                ],
            ],
        ],
    ],
    [],
    [
        'id' => 18,
        'value' => [
            'LINE_ID' => 'U-ending-1',
            'loop_count' => 2,
            'collected_endings' => 'END-01,END-05',
            'collected_stamps' => null,
        ],
    ],
);
$endingRepository = new StampRallyRecordRepository($endingClient, 'stamp_rally_records');
$endingResult = $endingRepository->saveEndingByLineId('U-ending-1', 'END-05');
assertSameValue('END-05', $endingResult['endingId'], 'Repository returns saved ending ID');
assertSameValue(['END-01', 'END-05'], $endingResult['endings'], 'Repository appends new ending IDs');
assertSameValue(2, $endingResult['loopCount'], 'Repository advances loop count for new endings');
assertFalseValue($endingResult['cleared'], 'Repository does not clear before the true ending condition');
assertSameValue([
    [
        'method' => 'GET',
        'path' => '/api/data/stamp_rally_records/query-column',
        'query' => [
            'q' => 'LINE_ID eq U-ending-1',
            'count' => 1,
        ],
    ],
    [
        'method' => 'PUT',
        'path' => '/api/data/stamp_rally_records/18',
        'payload' => [
            'value' => [
                'collected_endings' => 'END-01,END-05',
                'loop_count' => 2,
                'collected_stamps' => null,
            ],
        ],
    ],
], $endingClient->calls, 'Repository updates collected endings and resets stamps for the next loop with PUT');

$duplicateEndingCompletedLoopClient = new FakeExmentClient(
    [
        [
            'data' => [
                [
                    'id' => 21,
                    'value' => [
                        'LINE_ID' => 'U-ending-duplicate',
                        'loop_count' => 2,
                        'collected_endings' => 'END-AI',
                        'collected_stamps' => '1,2,3,4,5',
                    ],
                ],
            ],
        ],
    ],
    [],
    [
        'id' => 21,
        'value' => [
            'LINE_ID' => 'U-ending-duplicate',
            'loop_count' => 3,
            'collected_endings' => 'END-AI',
            'collected_stamps' => null,
        ],
    ],
);
$duplicateEndingCompletedLoopRepository = new StampRallyRecordRepository($duplicateEndingCompletedLoopClient, 'stamp_rally_records');
$duplicateEndingCompletedLoopResult = $duplicateEndingCompletedLoopRepository->saveEndingByLineId('U-ending-duplicate', 'END-AI');
assertSameValue(['END-AI'], $duplicateEndingCompletedLoopResult['endings'], 'Repository deduplicates already collected ending IDs');
assertSameValue(3, $duplicateEndingCompletedLoopResult['loopCount'], 'Repository advances completed loops even when the ending ID already exists');
assertFalseValue($duplicateEndingCompletedLoopResult['cleared'], 'Repository does not clear END-AI before loop 3');
assertSameValue([
    [
        'method' => 'GET',
        'path' => '/api/data/stamp_rally_records/query-column',
        'query' => [
            'q' => 'LINE_ID eq U-ending-duplicate',
            'count' => 1,
        ],
    ],
    [
        'method' => 'PUT',
        'path' => '/api/data/stamp_rally_records/21',
        'payload' => [
            'value' => [
                'collected_endings' => 'END-AI',
                'loop_count' => 3,
                'collected_stamps' => null,
            ],
        ],
    ],
], $duplicateEndingCompletedLoopClient->calls, 'Repository resets stamps for duplicate endings when the current loop is complete');

$trueEndingClient = new FakeExmentClient([
    [
        'data' => [
            [
                'id' => 19,
                'value' => [
                    'LINE_ID' => 'U-ending-2',
                    'loop_count' => 3,
                    'collected_endings' => 'END-AI',
                    'collected_stamps' => '1,2,3,4,5',
                ],
            ],
        ],
    ],
]);
$trueEndingRepository = new StampRallyRecordRepository($trueEndingClient, 'stamp_rally_records');
$trueEndingResult = $trueEndingRepository->saveEndingByLineId('U-ending-2', 'END-AI');
assertTrueValue($trueEndingResult['cleared'], 'Repository marks END-AI on loop 3 as cleared');
assertTrueValue(isset($trueEndingClient->calls[1]['payload']['value']['cleared_at']), 'Repository stores cleared_at for true ending');
assertSameValue(3, $trueEndingClient->calls[1]['payload']['value']['loop_count'], 'Repository keeps loop count capped at 3');
assertFalseValue(array_key_exists('collected_stamps', $trueEndingClient->calls[1]['payload']['value']), 'Repository keeps final-loop stamps when true ending clears');

$ineligibleClearClient = new FakeExmentClient([
    [
        'data' => [
            [
                'id' => 20,
                'value' => [
                    'LINE_ID' => 'U-ending-3',
                    'loop_count' => 3,
                    'collected_endings' => 'END-AI',
                    'collected_stamps' => '1,2,3,4',
                ],
            ],
        ],
    ],
]);
$ineligibleClearRepository = new StampRallyRecordRepository($ineligibleClearClient, 'stamp_rally_records');
$ineligibleClearResult = $ineligibleClearRepository->saveEndingByLineId('U-ending-3', 'END-AI');
assertFalseValue($ineligibleClearResult['cleared'], 'Repository keeps END-AI uncleared until server-side stamp progression is complete');
assertFalseValue(isset($ineligibleClearClient->calls[1]['payload']['value']['cleared_at']), 'Repository does not write cleared_at when clear progression is incomplete');

$invalidEndingClient = new FakeExmentClient();
$invalidEndingRepository = new StampRallyRecordRepository($invalidEndingClient, 'stamp_rally_records');
try {
    $invalidEndingRepository->saveEndingByLineId('U-ending-4', 'END-EX');
    assertTrueValue(false, 'Repository rejects unknown ending IDs');
} catch (DomainException $exception) {
    assertSameValue('invalid_ending', $exception->getMessage(), 'Repository reports invalid ending IDs with invalid_ending');
}
assertSameValue([], $invalidEndingClient->calls, 'Repository does not query Exment when ending ID is invalid');

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
    'STAMP_TOKEN_1' => 'route-token-1',
    'STAMP_TOKEN_2' => 'route-token-2',
    'STAMP_TOKEN_3' => 'route-token-3',
    'STAMP_TOKEN_4' => 'route-token-4',
    'STAMP_TOKEN_5' => 'route-token-5',
]);

$stampRouteRepositoryClient = new FakeExmentClient(
    [[
        'data' => [[
            'id' => 55,
            'value' => [
                'LINE_ID' => 'U-route-stamp',
                'collected_stamps' => '1',
            ],
        ]],
    ]],
    [],
    [
        'id' => 55,
        'value' => [
            'LINE_ID' => 'U-route-stamp',
            'collected_stamps' => '1,2',
        ],
    ],
);
$stampRouteRepository = new StampRallyRecordRepository($stampRouteRepositoryClient, 'stamp_rally_records');
$stampRouteApp = createRallyAppWithConfig($configuredChoiceConfig, $stampRouteRepository);

setLineUserIdForSession(null);
$unauthorizedStampRequest = (new ServerRequestFactory())->createServerRequest('POST', RouteNames::STAMP_RALLY_STAMP)
    ->withParsedBody(['token' => 'route-token-2']);
$unauthorizedStampResponse = $stampRouteApp->handle($unauthorizedStampRequest);
$unauthorizedStampPayload = json_decode((string) $unauthorizedStampResponse->getBody(), true);
assertSameValue(401, $unauthorizedStampResponse->getStatusCode(), 'Stamp rally stamp route returns 401 when session user is missing');
assertSameValue(['ok' => false, 'requiresLogin' => true], $unauthorizedStampPayload, 'Stamp rally stamp route returns login-required payload');

setLineUserIdForSession('U-route-stamp');
$csrfRejectedStampRequest = (new ServerRequestFactory())->createServerRequest('POST', RouteNames::STAMP_RALLY_STAMP)
    ->withParsedBody(['token' => 'route-token-2']);
$csrfRejectedStampResponse = $stampRouteApp->handle($csrfRejectedStampRequest);
$csrfRejectedStampPayload = json_decode((string) $csrfRejectedStampResponse->getBody(), true);
assertSameValue(403, $csrfRejectedStampResponse->getStatusCode(), 'Stamp rally stamp route rejects requests without same-origin headers');
assertSameValue(['ok' => false, 'error' => 'invalid_origin'], $csrfRejectedStampPayload, 'Stamp rally stamp route reports invalid origin');

$unknownTokenRequest = (new ServerRequestFactory())->createServerRequest('POST', RouteNames::STAMP_RALLY_STAMP)
    ->withHeader('Origin', 'https://example.test')
    ->withParsedBody(['token' => 'bad-token']);
$unknownTokenResponse = $stampRouteApp->handle($unknownTokenRequest);
$unknownTokenPayload = json_decode((string) $unknownTokenResponse->getBody(), true);
assertSameValue(403, $unknownTokenResponse->getStatusCode(), 'Stamp rally stamp route rejects unknown tokens');
assertSameValue(['ok' => false, 'error' => 'unknown_token'], $unknownTokenPayload, 'Stamp rally stamp route reports unknown token');

$successfulStampRequest = (new ServerRequestFactory())->createServerRequest('POST', RouteNames::STAMP_RALLY_STAMP)
    ->withHeader('Origin', 'https://example.test')
    ->withParsedBody(['token' => 'route-token-2']);
$successfulStampResponse = $stampRouteApp->handle($successfulStampRequest);
$successfulStampPayload = json_decode((string) $successfulStampResponse->getBody(), true);
assertSameValue(200, $successfulStampResponse->getStatusCode(), 'Stamp rally stamp route saves stamp when request is valid');
assertSameValue([
    'ok' => true,
    'stamp' => 2,
    'stamps' => [1, 2],
    'alreadyAcquired' => false,
    'record' => [
        'id' => 55,
        'value' => [
            'LINE_ID' => 'U-route-stamp',
            'collected_stamps' => '1,2',
        ],
    ],
], $successfulStampPayload, 'Stamp rally stamp route returns acquired stamp payload');

$duplicateStampRouteClient = new FakeExmentClient([
    [
        'data' => [[
            'id' => 57,
            'value' => [
                'LINE_ID' => 'U-route-stamp',
                'collected_stamps' => '1,2',
            ],
        ]],
    ],
]);
$duplicateStampRouteRepository = new StampRallyRecordRepository($duplicateStampRouteClient, 'stamp_rally_records');
$duplicateStampRouteApp = createRallyAppWithConfig($configuredChoiceConfig, $duplicateStampRouteRepository);
$duplicateStampRequest = (new ServerRequestFactory())->createServerRequest('POST', RouteNames::STAMP_RALLY_STAMP)
    ->withHeader('Origin', 'https://example.test')
    ->withParsedBody(['token' => 'route-token-2']);
$duplicateStampResponse = $duplicateStampRouteApp->handle($duplicateStampRequest);
$duplicateStampPayload = json_decode((string) $duplicateStampResponse->getBody(), true);
assertSameValue(409, $duplicateStampResponse->getStatusCode(), 'Stamp rally stamp route rejects already acquired stamps');
assertSameValue([
    'ok' => false,
    'error' => 'stamp_already_acquired',
    'stamp' => 2,
    'stamps' => [1, 2],
    'record' => [
        'id' => 57,
        'value' => [
            'LINE_ID' => 'U-route-stamp',
            'collected_stamps' => '1,2',
        ],
    ],
], $duplicateStampPayload, 'Stamp rally stamp route returns existing stamps when duplicate stamp is rejected');
assertSameValue(1, count($duplicateStampRouteClient->calls), 'Stamp rally stamp route does not update duplicate stamp records');

$outOfOrderRouteClient = new FakeExmentClient([
    [
        'data' => [[
            'id' => 56,
            'value' => [
                'LINE_ID' => 'U-route-stamp',
                'collected_stamps' => '1',
            ],
        ]],
    ],
]);
$outOfOrderRouteRepository = new StampRallyRecordRepository($outOfOrderRouteClient, 'stamp_rally_records');
$outOfOrderRouteApp = createRallyAppWithConfig($configuredChoiceConfig, $outOfOrderRouteRepository);
$outOfOrderStampRequest = (new ServerRequestFactory())->createServerRequest('POST', RouteNames::STAMP_RALLY_STAMP)
    ->withHeader('Origin', 'https://example.test')
    ->withParsedBody(['token' => 'route-token-3']);
$outOfOrderStampResponse = $outOfOrderRouteApp->handle($outOfOrderStampRequest);
$outOfOrderStampPayload = json_decode((string) $outOfOrderStampResponse->getBody(), true);
assertSameValue(409, $outOfOrderStampResponse->getStatusCode(), 'Stamp rally stamp route rejects out-of-order stamps');
assertSameValue([
    'ok' => false,
    'error' => 'out_of_order_stamp',
    'stamp' => 3,
], $outOfOrderStampPayload, 'Stamp rally stamp route reports out-of-order stamp');

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

$thirdLoopChoiceRouteClient = new FakeExmentClient(
    [[
        'data' => [[
            'id' => 45,
            'value' => [
                'LINE_ID' => 'U-route-3',
                'loop_count' => 3,
            ],
        ]],
    ]],
    [],
    [
        'id' => 45,
        'value' => [
            'LINE_ID' => 'U-route-3',
            'loop_count' => 3,
            'loop3_choices' => 'B,A,B',
        ],
    ],
);
$thirdLoopChoiceRouteRepository = new StampRallyRecordRepository($thirdLoopChoiceRouteClient, 'stamp_rally_records');
$thirdLoopChoiceRouteApp = createRallyAppWithConfig($configuredChoiceConfig, $thirdLoopChoiceRouteRepository);

setLineUserIdForSession('U-route-3');
$thirdLoopChoiceRequest = (new ServerRequestFactory())->createServerRequest('POST', RouteNames::STAMP_RALLY_CHOICE)
    ->withHeader('Origin', 'https://example.test')
    ->withParsedBody(['choices' => ['B', 'A', 'B']]);
$thirdLoopChoiceResponse = $thirdLoopChoiceRouteApp->handle($thirdLoopChoiceRequest);
$thirdLoopChoicePayload = json_decode((string) $thirdLoopChoiceResponse->getBody(), true);
assertSameValue(200, $thirdLoopChoiceResponse->getStatusCode(), 'Stamp rally choice route saves third-loop choices when request is valid');
assertSameValue('loop3_choices', $thirdLoopChoicePayload['column'], 'Stamp rally choice route returns loop3_choices for third-loop records');
assertSameValue([
    [
        'method' => 'GET',
        'path' => '/api/data/stamp_rally_records/query-column',
        'query' => [
            'q' => 'LINE_ID eq U-route-3',
            'count' => 1,
        ],
    ],
    [
        'method' => 'PUT',
        'path' => '/api/data/stamp_rally_records/45',
        'payload' => [
            'value' => [
                'loop3_choices' => 'B,A,B',
            ],
        ],
    ],
], $thirdLoopChoiceRouteClient->calls, 'Stamp rally choice route writes third-loop choices using repository');

$endingRouteRepositoryClient = new FakeExmentClient(
    [[
        'data' => [[
            'id' => 66,
            'value' => [
                'LINE_ID' => 'U-route-ending',
                'loop_count' => 1,
                'collected_endings' => '',
                'collected_stamps' => '1,2,3,4,5',
            ],
        ]],
    ]],
    [],
    [
        'id' => 66,
        'value' => [
            'LINE_ID' => 'U-route-ending',
            'loop_count' => 2,
            'collected_endings' => 'END-01',
            'collected_stamps' => null,
        ],
    ],
);
$endingRouteRepository = new StampRallyRecordRepository($endingRouteRepositoryClient, 'stamp_rally_records');
$endingRouteApp = createRallyAppWithConfig($configuredChoiceConfig, $endingRouteRepository);

setLineUserIdForSession('U-route-ending');
$successfulEndingRequest = (new ServerRequestFactory())->createServerRequest('POST', RouteNames::STAMP_RALLY_ENDING)
    ->withHeader('Origin', 'https://example.test')
    ->withParsedBody(['endingId' => 'END-01']);
$successfulEndingResponse = $endingRouteApp->handle($successfulEndingRequest);
$successfulEndingPayload = json_decode((string) $successfulEndingResponse->getBody(), true);
assertSameValue(200, $successfulEndingResponse->getStatusCode(), 'Stamp rally ending route saves endings when request is valid');
assertSameValue([
    'ok' => true,
    'endingId' => 'END-01',
    'endings' => ['END-01'],
    'loopCount' => 2,
    'cleared' => false,
    'record' => [
        'id' => 66,
        'value' => [
            'LINE_ID' => 'U-route-ending',
            'loop_count' => 2,
            'collected_endings' => 'END-01',
            'collected_stamps' => null,
        ],
    ],
], $successfulEndingPayload, 'Stamp rally ending route returns saved ending payload');
assertSameValue([
    [
        'method' => 'GET',
        'path' => '/api/data/stamp_rally_records/query-column',
        'query' => [
            'q' => 'LINE_ID eq U-route-ending',
            'count' => 1,
        ],
    ],
    [
        'method' => 'PUT',
        'path' => '/api/data/stamp_rally_records/66',
        'payload' => [
            'value' => [
                'collected_endings' => 'END-01',
                'loop_count' => 2,
                'collected_stamps' => null,
            ],
        ],
    ],
], $endingRouteRepositoryClient->calls, 'Stamp rally ending route writes endings and resets stamps using repository');

$invalidEndingRouteRepositoryClient = new FakeExmentClient();
$invalidEndingRouteRepository = new StampRallyRecordRepository($invalidEndingRouteRepositoryClient, 'stamp_rally_records');
$invalidEndingRouteApp = createRallyAppWithConfig($configuredChoiceConfig, $invalidEndingRouteRepository);

setLineUserIdForSession('U-route-ending');
$invalidEndingRequest = (new ServerRequestFactory())->createServerRequest('POST', RouteNames::STAMP_RALLY_ENDING)
    ->withHeader('Origin', 'https://example.test')
    ->withParsedBody(['endingId' => 'END-EX']);
$invalidEndingResponse = $invalidEndingRouteApp->handle($invalidEndingRequest);
$invalidEndingPayload = json_decode((string) $invalidEndingResponse->getBody(), true);
assertSameValue(400, $invalidEndingResponse->getStatusCode(), 'Stamp rally ending route rejects unknown ending IDs');
assertSameValue([
    'ok' => false,
    'error' => 'invalid_ending',
], $invalidEndingPayload, 'Stamp rally ending route reports invalid ending IDs');
assertSameValue([], $invalidEndingRouteRepositoryClient->calls, 'Stamp rally ending route does not call Exment for unknown ending IDs');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} PHP test assertion(s) failed.\n");
    exit(1);
}

fwrite(STDOUT, "PHP tests passed.\n");
