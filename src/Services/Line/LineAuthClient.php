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

    public function fetchUserIdFromAuthorizationCode(string $code, string $nonce): string
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
        if (!is_array($tokenPayload) || !isset($tokenPayload['id_token']) || !is_string($tokenPayload['id_token'])) {
            throw new RuntimeException('LINE token endpoint returned invalid JSON.');
        }

        $verifyResponse = $this->transport->request(
            'POST',
            'https://api.line.me/oauth2/v2.1/verify',
            [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            http_build_query([
                'id_token' => $tokenPayload['id_token'],
                'client_id' => $this->config->channelId,
                'nonce' => $nonce,
            ]),
        );

        if ($verifyResponse->statusCode < 200 || $verifyResponse->statusCode >= 300) {
            throw new RuntimeException("LINE verify endpoint returned HTTP {$verifyResponse->statusCode}.");
        }

        $verifyPayload = json_decode($verifyResponse->body, true);
        if (!is_array($verifyPayload) || !isset($verifyPayload['sub']) || !is_string($verifyPayload['sub'])) {
            throw new RuntimeException('LINE verify endpoint returned invalid JSON.');
        }

        $userId = trim($verifyPayload['sub']);
        if ($userId === '') {
            throw new RuntimeException('LINE verify endpoint returned empty user ID.');
        }

        return $userId;
    }
}
