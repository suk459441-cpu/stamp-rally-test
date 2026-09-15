<?php

declare(strict_types=1);

namespace App\Services\Exment;

final class HttpResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
    ) {
    }
}
