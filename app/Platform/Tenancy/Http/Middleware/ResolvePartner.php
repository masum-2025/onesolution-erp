<?php

namespace App\Platform\Tenancy\Http\Middleware;

use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Exceptions\OrganizationAccessDenied;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the partner for partner-console routes, from the token only.
 * Runs after auth:sanctum.
 */
class ResolvePartner
{
    public function __construct(private ContextResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $partnerId = $token instanceof PersonalAccessToken ? $token->getAttribute('partner_id') : null;

        if ($user === null || $partnerId === null) {
            throw OrganizationAccessDenied::noPartnerAccess();
        }

        $this->resolver->enterPartner($user, $partnerId);

        return $next($request);
    }
}
