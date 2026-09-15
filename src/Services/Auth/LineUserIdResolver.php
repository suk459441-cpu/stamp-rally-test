<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Psr\Http\Message\ServerRequestInterface;

final class LineUserIdResolver
{
    public function resolve(ServerRequestInterface $request): ?string
    {
        $cookies = $request->getCookieParams();
        $userId = trim((string) ($cookies['user_id'] ?? ''));

        return $userId === '' ? null : $userId;
    }
}
