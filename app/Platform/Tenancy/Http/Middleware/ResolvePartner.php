<?php

namespace App\Platform\Tenancy\Http\Middleware;

use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Context\ContextSource;
use App\Platform\Tenancy\Exceptions\OrganizationAccessDenied;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the partner for partner-console routes, from the token or the browser
 * session only (ContextSource). Runs after auth:sanctum.
 */
class ResolvePartner
{
    public function __construct(
        private ContextResolver $resolver,
        private ContextSource $source,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $partnerId = $user === null ? null : $this->source->partnerId($request);

        if ($user === null || $partnerId === null) {
            throw OrganizationAccessDenied::noPartnerAccess();
        }

        $this->resolver->enterPartner($user, $partnerId);

        return $next($request);
    }
}
