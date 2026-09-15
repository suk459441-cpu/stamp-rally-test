<?php

declare(strict_types=1);

namespace App\Services\Exment;

use RuntimeException;

final class NativeHttpTransport implements HttpTransportInterface
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        if (function_exists('curl_init')) {
            return $this->requestWithCurl($method, $url, $headers, $body);
        }

        return $this->requestWithStream($method, $url, $headers, $body);
    }

    /**
     * @param array<string, string> $headers
     */
    private function requestWithCurl(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_TIMEOUT => 15,
        ]);

        $caFile = $this->resolveCaFile();
        if ($caFile !== null) {
            curl_setopt($curl, CURLOPT_CAINFO, $caFile);
        }

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($responseBody === false) {
            throw new RuntimeException($error !== '' ? $error : 'Exment request failed.');
        }

        return new HttpResponse($statusCode, (string) $responseBody);
    }

    /**
     * @param array<string, string> $headers
     */
    private function requestWithStream(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $this->formatHeaders($headers)),
                'content' => $body ?? '',
                'ignore_errors' => true,
                'timeout' => 15,
            ],
            'ssl' => array_filter([
                'cafile' => $this->resolveCaFile(),
            ]),
        ]);

        $responseBody = file_get_contents($url, false, $context);
        if ($responseBody === false) {
            throw new RuntimeException('Exment request failed.');
        }

        $statusCode = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
                $statusCode = (int) $matches[1];
                break;
            }
        }

        return new HttpResponse($statusCode, $responseBody);
    }

    /**
     * @param array<string, string> $headers
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return $lines;
    }

    private function resolveCaFile(): ?string
    {
        foreach (['CURL_CA_BUNDLE', 'SSL_CERT_FILE'] as $key) {
            $path = getenv($key);
            if (is_string($path) && is_file($path)) {
                return $path;
            }
        }

        $localCaFile = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'windows-ca.pem';

        return is_file($localCaFile) ? $localCaFile : null;
    }
}
