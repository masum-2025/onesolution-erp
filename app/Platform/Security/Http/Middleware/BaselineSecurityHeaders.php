<?php

namespace App\Platform\Security\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers every response carries, pages and API alike (Phase 8-2): no MIME
 * sniffing, a strict referrer, HSTS on HTTPS (for partner domains too), and
 * "noindex" outside public pages.
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

        // Search engines keep out of the app, the API and files. Public pages meant to be
        // found (careers, school sites) say so themselves and keep their own answer.
        if (! $response->headers->has('X-Robots-Tag') && ! $this->publicPage($request)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        // Browsers ignore HSTS over plain HTTP, and sending it there could pin a
        // development host; only HTTPS responses carry it.
        if ($request->isSecure() && config('security.hsts.enabled')) {
            $response->headers->set('Strict-Transport-Security', $this->hsts());
        }

        return $response;
    }

    /** Paths of public pages: they decide per page (robots meta, X-Robots-Tag), and robots.txt itself. */
    private function publicPage(Request $request): bool
    {
        return $request->is('careers/*', 's/*', 'robots.txt');
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
