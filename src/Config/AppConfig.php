<?php

declare(strict_types=1);

namespace App\Config;

final class AppConfig
{
    public function __construct(
        public readonly string $env,
        public readonly bool $debug,
        public readonly string $basePath,
        public readonly LineConfig $line,
        public readonly ExmentConfig $exment,
    ) {
    }

    /**
     * @param array<string, string|bool|int|null> $env
     */
    public static function fromEnv(array $env = []): self
    {
        $env = $env === [] ? $_ENV : $env;

        return new self(
            env: self::stringValue($env, 'APP_ENV', 'production'),
            debug: self::boolValue($env, 'APP_DEBUG', false),
            basePath: self::stringValue($env, 'APP_BASE_PATH', ''),
            line: new LineConfig(
                channelId: self::stringValue($env, 'LINE_CHANNEL_ID', ''),
                channelSecret: self::stringValue($env, 'LINE_CHANNEL_SECRET', ''),
                redirectUri: self::stringValue($env, 'LINE_REDIRECT_URI', ''),
            ),
            exment: new ExmentConfig(
                baseUrl: rtrim(self::stringValue($env, 'EXMENT_BASE_URL', ''), '/'),
                clientId: self::stringValue($env, 'EXMENT_CLIENT_ID', ''),
                clientSecret: self::stringValue($env, 'EXMENT_CLIENT_SECRET', ''),
                username: self::stringValue($env, 'EXMENT_USERNAME', ''),
                password: self::stringValue($env, 'EXMENT_PASSWORD', ''),
                stampRallyTable: self::stringValue($env, 'EXMENT_STAMP_RALLY_TABLE', 'stamp_rally'),
            ),
        );
    }

    /**
     * @param array<string, string|bool|int|null> $env
     */
    private static function stringValue(array $env, string $key, string $default): string
    {
        $value = $env[$key] ?? $default;
        return trim((string) $value);
    }

    /**
     * @param array<string, string|bool|int|null> $env
     */
    private static function boolValue(array $env, string $key, bool $default): bool
    {
        $value = $env[$key] ?? $default;

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
