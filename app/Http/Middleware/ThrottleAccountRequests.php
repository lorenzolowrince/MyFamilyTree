<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limits registration and password-reset submissions (5 per minute per email + IP,
 * docs/architecture/06-security.md). Fortify registers those routes itself and only
 * throttles sign-in, so the limit is applied here by route name.
 */
class ThrottleAccountRequests
{
    private const ROUTES = ['register.store', 'password.email', 'password.update'];

    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs(...self::ROUTES)) {
            return $next($request);
        }

        return $this->throttle->handle($request, $next, 'account-requests');
    }
}
