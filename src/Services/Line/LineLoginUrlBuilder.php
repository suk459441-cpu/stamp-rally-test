<?php

declare(strict_types=1);

namespace App\Services\Line;

use App\Config\LineConfig;

final class LineLoginUrlBuilder
{
    public function __construct(private readonly LineConfig $config)
    {
    }

    public function build(string $state, string $nonce): string
    {
        return 'https://access.line.me/oauth2/v2.1/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config->channelId,
            'redirect_uri' => $this->config->redirectUri,
            'state' => $state,
            'scope' => 'profile openid',
            'nonce' => $nonce,
        ]);
    }
}
