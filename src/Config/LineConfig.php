<?php

declare(strict_types=1);

namespace App\Config;

final class LineConfig
{
    public function __construct(
        public readonly string $channelId,
        public readonly string $channelSecret,
        public readonly string $redirectUri,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->channelId !== ''
            && $this->channelSecret !== ''
            && $this->redirectUri !== '';
    }
}
