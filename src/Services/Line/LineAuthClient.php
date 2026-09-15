<?php

declare(strict_types=1);

namespace App\Services\Line;

use App\Config\LineConfig;
use App\Services\Exment\HttpTransportInterface;
use App\Services\Exment\NativeHttpTransport;
use RuntimeException;

final class LineAuthClient
{
    public function __construct(
        private readonly LineConfig $config,
        private readonly HttpTransportInterface $transport = new NativeHttpTransport(),
    ) {
    }

    public function fetchUserIdFromAuthorizationCode(string $code): string
    {
        $tokenResponse = $this->transport->request(
            'POST',
            'https://api.line.me/oauth2/v2.1/token',
            [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->config->redirectUri,
                'client_id' => $this->config->channelId,
                'client_secret' => $this->config->channelSecret,
            ]),
        );

        if ($tokenResponse->statusCode < 200 || $tokenResponse->statusCode >= 300) {
            throw new RuntimeException("LINE token endpoint returned HTTP {$tokenResponse->statusCode}.");
        }

        $tokenPayload = json_decode($tokenResponse->body, true);
        if (!is_array($tokenPayload) || !isset($tokenPayload['access_token']) || !is_string($tokenPayload['access_token'])) {
            throw new RuntimeException('LINE token endpoint returned invalid JSON.');
        }

        $profileResponse = $this->transport->request(
            'GET',
            'https://api.line.me/v2/profile',
            [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $tokenPayload['access_token'],
            ],
        );

        if ($profileResponse->statusCode < 200 || $profileResponse->statusCode >= 300) {
            throw new RuntimeException("LINE profile endpoint returned HTTP {$profileResponse->statusCode}.");
        }

        $profilePayload = json_decode($profileResponse->body, true);
        if (!is_array($profilePayload) || !isset($profilePayload['userId']) || !is_string($profilePayload['userId'])) {
            throw new RuntimeException('LINE profile endpoint returned invalid JSON.');
        }

        return trim($profilePayload['userId']);
    }
}
