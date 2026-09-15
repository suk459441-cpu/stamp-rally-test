<?php

declare(strict_types=1);

namespace App\Services\Exment;

interface ExmentClientInterface
{
    /**
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload): array;
}
