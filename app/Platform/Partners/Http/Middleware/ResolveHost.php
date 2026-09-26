<?php

namespace App\Platform\Partners\Http\Middleware;

use App\Platform\Partners\HostContext;
use App\Platform\Partners\Services\HostResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every request: which account does this address belong to? Platform hosts
 * serve everyone; an active partner domain serves that partner (and its
 * browser session is trusted by Sanctum for this request); anything else is
 * refused, never served as another tenant.
 */
class ResolveHost
{
    public function __construct(private HostResolver $hosts, private HostContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());

        if ($this->hosts->isPlatformHost($host) || $request->is('up')) {
            $this->context->forPlatform();

            return $next($request);
        }

        $domain = $this->hosts->activeDomain($host);

        if ($domain === null) {
            $message = __('partners.errors.unknown_host');

            return $request->expectsJson() || $request->is('api/*', 'session/*')
                ? response()->json(['message' => $message, 'code' => 'unknown_host'], 404)
                : response($message, 404)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $this->context->forDomain($domain);

        // The browser app on this verified domain uses cookie sessions like the house domain.
        config(['sanctum.stateful' => [
            ...(array) config('sanctum.stateful'),
            $host,
            $host.':'.$request->getPort(),
        ]]);

        return $next($request);
    }
}
