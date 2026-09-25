<?php

namespace App\Platform\Tenancy\Http\Middleware;

use App\Http\Middleware\ApplyRequestLocale;
use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Context\ContextSource;
use App\Platform\Tenancy\Exceptions\MissingTenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the tenant for client-area routes. The organization id is read from
 * the authenticated token or the browser session (ContextSource) only; ids in
 * the body, query or headers are ignored. Runs after auth:sanctum.
 */
class ResolveOrganization
{
    public function __construct(
        private ContextResolver $resolver,
        private ContextSource $source,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organizationId = $user === null ? null : $this->source->organizationId($request);

        if ($user === null || $organizationId === null) {
            throw new MissingTenantContext;
        }

        $context = $this->resolver->enterOrganization($user, $organizationId);

        // The organization's language, unless the user picked one for this request.
        $locale = $context->locale();
        if (! ApplyRequestLocale::wasChosen($request) && $locale !== null && in_array($locale, config('tenancy.supported_locales'), true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
