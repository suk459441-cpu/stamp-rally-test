<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Psr\Http\Message\ServerRequestInterface;

final class LineUserIdResolver
{
    public function resolve(ServerRequestInterface $request): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $userId = trim((string) ($_SESSION['line_user_id'] ?? ''));

        return $userId === '' ? null : $userId;
    }
}
