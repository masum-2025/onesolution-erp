<?php

namespace App\Http\Middleware;

use App\Platform\Identity\Contracts\BotCheck;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers for pages: a nonce-based Content Security Policy
 * (no inline script or style without the nonce, no third-party origins
 * except the configured bot check on public forms),
 * no framing, no MIME sniffing. In local development the Vite dev server
 * origin is allowed as well.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        // A response that sets its own, stricter policy keeps it (e.g. message previews).
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->policy($nonce));
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if (! $response->headers->has('X-Frame-Options')) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        return $response;
    }

    private function policy(string $nonce): string
    {
        $dev = $this->devServer();
        $devWs = $dev === '' ? '' : ' '.preg_replace('#^http#', 'ws', $dev);
        // The bot check on public forms (e.g. Cloudflare Turnstile) loads a script and a frame.
        $bot = implode('', array_map(fn (string $origin) => " {$origin}", app(BotCheck::class)->origins()));

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$bot}".($dev === '' ? '' : " {$dev}"),
            "frame-src 'self'{$bot}",
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
