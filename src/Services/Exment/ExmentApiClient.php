<?php

declare(strict_types=1);

namespace App\Services\Exment;

use App\Config\ExmentConfig;
use RuntimeException;

final class ExmentApiClient implements ExmentClientInterface
{
    private ?string $accessToken = null;

    public function __construct(
        private readonly ExmentConfig $config,
        private readonly HttpTransportInterface $transport = new NativeHttpTransport(),
    ) {
    }

    public function get(string $path, array $query = []): array
    {
        return $this->requestJson('GET', $path, $query);
    }

    public function post(string $path, array $payload): array
    {
        return $this->requestJson('POST', $path, [], $payload);
    }

    /**
     * @param array<string, string|int> $query
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $path, array $query = [], ?array $payload = null): array
    {
        $url = $this->buildUrl($path, $query);
        $body = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];

        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $response = $this->transport->request($method, $url, $headers, $body);
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new RuntimeException("Exment API returned HTTP {$response->statusCode}.");
        }

        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Exment API returned invalid JSON.');
        }

        return $decoded;
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $response = $this->transport->request(
            'POST',
            $this->buildUrl('/oauth/token'),
            [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            http_build_query([
                'grant_type' => 'api_key',
                'client_id' => $this->config->clientId,
                'client_secret' => $this->config->clientSecret,
                'api_key' => $this->config->apiKey,
                'scope' => 'value_read value_write',
            ])
        );

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new RuntimeException("Exment token endpoint returned HTTP {$response->statusCode}.");
        }

        $decoded = json_decode($response->body, true);
        if (!is_array($decoded) || !isset($decoded['access_token']) || !is_string($decoded['access_token'])) {
            throw new RuntimeException('Exment token endpoint returned invalid JSON.');
        }

        $this->accessToken = $decoded['access_token'];

        return $this->accessToken;
    }

    /**
     * @param array<string, string|int> $query
     */
    private function buildUrl(string $path, array $query = []): string
    {
        $url = $this->config->baseUrl . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }
}
