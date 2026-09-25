<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers for pages: a nonce-based Content Security Policy
 * (no inline script or style without the nonce, no third-party origins),
 * no framing, no MIME sniffing. In local development the Vite dev server
 * origin is allowed as well.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('Content-Security-Policy', $this->policy($nonce));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        return $response;
    }

    private function policy(string $nonce): string
    {
        $dev = $this->devServer();
        $devWs = $dev === '' ? '' : ' '.preg_replace('#^http#', 'ws', $dev);

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'".($dev === '' ? '' : " {$dev}"),
            "style-src 'self' 'nonce-{$nonce}'".($dev === '' ? '' : " {$dev}"),
            "img-src 'self' data: blob:".($dev === '' ? '' : " {$dev}"),
            "font-src 'self' data:".($dev === '' ? '' : " {$dev}"),
            "connect-src 'self'".($dev === '' ? '' : " {$dev}{$devWs}"),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /**
     * The Vite dev server origin, only while it is running locally.
     */
    private function devServer(): string
    {
        if (! app()->environment('local') || ! Vite::isRunningHot()) {
            return '';
        }

        $url = trim((string) file_get_contents(public_path('hot')));

        return preg_match('#^https?://[A-Za-z0-9.\-\[\]:]+$#', $url) === 1 ? $url : '';
    }
}
