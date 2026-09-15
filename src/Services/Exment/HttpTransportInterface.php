<?php

declare(strict_types=1);

namespace App\Services\Exment;

interface HttpTransportInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}
