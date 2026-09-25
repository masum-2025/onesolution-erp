<?php

namespace App\Platform\Tenancy\Http\Middleware;

use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Exceptions\MissingTenantContext;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the tenant for client-area routes. The organization id is read from
 * the authenticated token only; ids in the body, query or headers are ignored.
 * Runs after auth:sanctum.
 */
class ResolveOrganization
{
    public function __construct(private ContextResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $organizationId = $token instanceof PersonalAccessToken ? $token->getAttribute('organization_id') : null;

        if ($user === null || $organizationId === null) {
            throw new MissingTenantContext;
        }

        $context = $this->resolver->enterOrganization($user, $organizationId);

        $locale = $context->locale();
        if ($locale !== null && in_array($locale, config('tenancy.supported_locales'), true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
