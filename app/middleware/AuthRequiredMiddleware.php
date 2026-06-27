<?php

declare(strict_types=1);

namespace app\middleware;

use app\exception\ApiException;
use app\support\AuthSession;
use Closure;
use think\Request;
use think\Response;

class AuthRequiredMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!AuthSession::signedIn()) {
            throw new ApiException('Authentication required', 401, 'unauthenticated');
        }

        return $next($request);
    }
}
