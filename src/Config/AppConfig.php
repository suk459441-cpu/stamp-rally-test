<?php

declare(strict_types=1);

namespace App\Config;

final class AppConfig
{
    public function __construct(
        public readonly string $env,
        public readonly bool $debug,
        public readonly string $basePath,
        public readonly string $publicSiteUrl,
        public readonly LineConfig $line,
        public readonly ExmentConfig $exment,
        /** @var array<int, string> */
        public readonly array $stampTokens,
    ) {
    }

    /**
     * @param array<string, string|bool|int|null> $env
     */
    public static function fromEnv(?array $env = null): self
    {
        if ($env === null) {
            $processEnv = getenv();
            $env = array_merge(is_array($processEnv) ? $processEnv : [], $_ENV);
        }

        $siteUrl = rtrim(self::stringValue($env, 'NUXT_PUBLIC_SITE_URL', ''), '/');
        $lineRedirectUri = $siteUrl === '' ? '' : "{$siteUrl}/auth/line/callback";

        return new self(
            env: self::stringValue($env, 'APP_ENV', 'production'),
            debug: self::boolValue($env, 'APP_DEBUG', false),
            basePath: self::stringValue($env, 'APP_BASE_PATH', ''),
            publicSiteUrl: $siteUrl,
            line: new LineConfig(
                channelId: self::stringValue($env, 'NUXT_CHANNEL_ID', ''),
                channelSecret: self::stringValue($env, 'NUXT_CHANNEL_SECRET', ''),
                redirectUri: $lineRedirectUri,
            ),
            exment: new ExmentConfig(
                baseUrl: rtrim(self::stringValue($env, 'EXMENT_BASE_URL', ''), '/'),
                apiKey: self::stringValue($env, 'EXMENT_API_KEY', ''),
                clientId: self::stringValue($env, 'EXMENT_CLIENT_ID', ''),
                clientSecret: self::stringValue($env, 'EXMENT_CLIENT_SECRET', ''),
                stampRallyTable: self::stringValue($env, 'EXMENT_STAMP_RALLY_TABLE', 'digital_stamp_rally'),
            ),
            stampTokens: self::stampTokens($env),
        );
    }

    /**
     * @param array<string, string|bool|int|null> $env
     * @return array<int, string>
     */
    private static function stampTokens(array $env): array
    {
        $tokens = [];
        for ($stampId = 1; $stampId <= 5; $stampId++) {
            $token = self::stringValue($env, "STAMP_TOKEN_{$stampId}", '');
            if ($token !== '') {
                $tokens[$stampId] = $token;
            }
        }

        return $tokens;
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
