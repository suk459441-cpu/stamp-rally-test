<?php

declare(strict_types=1);

use App\Config\AppConfig;
use App\Routes\RouteNames;

require __DIR__ . '/../../src/Config/AppConfig.php';
require __DIR__ . '/../../src/Config/LineConfig.php';
require __DIR__ . '/../../src/Config/ExmentConfig.php';
require __DIR__ . '/../../src/Routes/RouteNames.php';

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

$config = AppConfig::fromEnv([
    'APP_ENV' => 'local',
    'APP_DEBUG' => 'true',
    'APP_BASE_PATH' => '/rally',
    'LINE_CHANNEL_ID' => 'line-channel',
    'LINE_CHANNEL_SECRET' => 'line-secret',
    'LINE_REDIRECT_URI' => 'https://example.test/auth/line/callback',
    'EXMENT_BASE_URL' => 'https://exment.example.test/',
    'EXMENT_CLIENT_ID' => 'exment-client',
    'EXMENT_CLIENT_SECRET' => 'exment-secret',
    'EXMENT_USERNAME' => 'exment-user',
    'EXMENT_PASSWORD' => 'exment-password',
    'EXMENT_STAMP_RALLY_TABLE' => 'stamp_rally_records',
]);

assertSameValue('local', $config->env, 'APP_ENV is loaded');
assertTrueValue($config->debug, 'APP_DEBUG true string becomes true');
assertSameValue('/rally', $config->basePath, 'APP_BASE_PATH is loaded');
assertTrueValue($config->line->isConfigured(), 'LINE config reports configured');
assertTrueValue($config->exment->isConfigured(), 'Exment config reports configured');
assertSameValue('https://exment.example.test', $config->exment->baseUrl, 'Exment base URL trims trailing slash');
assertSameValue('stamp_rally_records', $config->exment->stampRallyTable, 'Exment table name is loaded');

$defaultConfig = AppConfig::fromEnv([]);
assertSameValue('production', $defaultConfig->env, 'APP_ENV defaults to production');
assertFalseValue($defaultConfig->debug, 'APP_DEBUG defaults to false');
assertFalseValue($defaultConfig->line->isConfigured(), 'LINE config reports missing values');
assertFalseValue($defaultConfig->exment->isConfigured(), 'Exment config reports missing values');
assertSameValue('stamp_rally', $defaultConfig->exment->stampRallyTable, 'Exment table defaults to stamp_rally');

assertSameValue('/api/health', RouteNames::API_HEALTH, 'API health route is stable');
assertSameValue('/api/stamp-rally/health', RouteNames::STAMP_RALLY_HEALTH, 'Stamp rally health route is stable');
assertSameValue('/api/stamp-rally/init', RouteNames::STAMP_RALLY_INIT, 'Stamp rally init route is reserved');
assertSameValue('/api/stamp-rally/choice', RouteNames::STAMP_RALLY_CHOICE, 'Stamp rally choice route is reserved');
assertSameValue('/api/stamp-rally/ending', RouteNames::STAMP_RALLY_ENDING, 'Stamp rally ending route is reserved');
assertSameValue('/auth/line/start', RouteNames::LINE_LOGIN_START, 'LINE login start route is reserved');
assertSameValue('/auth/line/callback', RouteNames::LINE_LOGIN_CALLBACK, 'LINE login callback route is reserved');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} PHP test assertion(s) failed.\n");
    exit(1);
}

fwrite(STDOUT, "PHP tests passed.\n");
