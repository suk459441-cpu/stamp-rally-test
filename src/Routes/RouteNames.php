<?php

declare(strict_types=1);

namespace App\Routes;

final class RouteNames
{
    public const API_HEALTH = '/api/health';
    public const STAMP_RALLY_HEALTH = '/api/stamp-rally/health';
    public const STAMP_RALLY_INIT = '/api/stamp-rally/init';
    public const STAMP_RALLY_CHOICE = '/api/stamp-rally/choice';
    public const STAMP_RALLY_ENDING = '/api/stamp-rally/ending';
    public const LINE_LOGIN_START = '/auth/line/start';
    public const LINE_LOGIN_CALLBACK = '/auth/line/callback';
}
