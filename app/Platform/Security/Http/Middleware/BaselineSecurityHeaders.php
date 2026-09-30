<?php

namespace App\Platform\Security\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers every response carries, pages and API alike (Phase 8-2): no MIME
 * sniffing, a strict referrer, and HSTS on HTTPS (for partner domains too).
 * The page-only policy (CSP, framing) is SecurityHeaders on the web group.
 */
class BaselineSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        // Browsers ignore HSTS over plain HTTP, and sending it there could pin a
        // development host; only HTTPS responses carry it.
        if ($request->isSecure() && config('security.hsts.enabled')) {
            $response->headers->set('Strict-Transport-Security', $this->hsts());
        }

        return $response;
    }

    private function hsts(): string
    {
        return implode('; ', array_filter([
            'max-age='.max(0, (int) config('security.hsts.max_age')),
            config('security.hsts.include_subdomains') ? 'includeSubDomains' : null,
            config('security.hsts.preload') ? 'preload' : null,
        ]));
    }
}
