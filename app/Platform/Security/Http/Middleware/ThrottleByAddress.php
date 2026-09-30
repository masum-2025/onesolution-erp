<?php

namespace App\Platform\Security\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The per-address limit on /api (Phase 8-2). Deliberately not Laravel's
 * throttle middleware: that one is ordered after authentication, so requests
 * with a wrong or stolen-and-revoked token would be refused (401) forever
 * without ever being counted. This runs first and counts every request.
 */
class ThrottleByAddress
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'api-ip:'.$request->ip();
        $max = max(1, (int) config('security.api.per_ip'));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $retryAfter = RateLimiter::availableIn($key);

            throw new ThrottleRequestsException('Too Many Attempts.', headers: [
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => $max,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($key, 60);

        $response = $next($request);
        $response->headers->set('X-RateLimit-Address-Remaining', (string) RateLimiter::remaining($key, $max));

        return $response;
    }
}
