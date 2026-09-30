<?php

namespace App\Platform\Security\Http\Middleware;

use App\Platform\Security\SecurityLog;
use App\Platform\Tenancy\Context\CurrentContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes refused requests to the security log (Phase 8-2): access denied
 * (403) and rate limited (429), with the stable error code, so a spike of
 * either can be seen and alerted on (Phase 9).
 */
class LogRefusedRequests
{
    public function __construct(
        private SecurityLog $log,
        private CurrentContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $event = match ($response->getStatusCode()) {
            Response::HTTP_FORBIDDEN => 'access.denied',
            Response::HTTP_TOO_MANY_REQUESTS => 'request.rate_limited',
            default => null,
        };

        if ($event !== null) {
            $this->log->record($event, [
                'code' => $response instanceof JsonResponse ? $response->getData(true)['code'] ?? null : null,
                'user_id' => $request->user()?->getKey(),
                'organization_id' => $this->context->hasOrganization() ? $this->context->organization()->getKey() : null,
                'partner_id' => $this->context->hasPartnerConsole() ? $this->context->partner()->getKey() : null,
            ], 'warning');
        }

        return $response;
    }
}
