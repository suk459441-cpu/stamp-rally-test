<?php

declare(strict_types=1);

namespace App\Config;

final class ExmentConfig
{
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $apiKey,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $stampRallyTable,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== ''
            && $this->clientId !== ''
            && $this->clientSecret !== ''
            && $this->stampRallyTable !== '';
    }
}
