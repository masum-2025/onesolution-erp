<?php

namespace App\Platform\Tenancy\Http\Middleware;

use App\Http\Middleware\ApplyRequestLocale;
use App\Platform\Audit\AuditLogger;
use App\Platform\Security\SecurityLog;
use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Context\ContextSource;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Exceptions\MissingTenantContext;
use App\Platform\Tenancy\Exceptions\OrganizationAccessDenied;
use App\Platform\Tenancy\Exceptions\OrganizationNotFound;
use App\Platform\Tenancy\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
        private AuditLogger $audit,
        private SecurityLog $securityLog,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organizationId = $user === null ? null : $this->source->organizationId($request);
        $grantId = $user === null || $organizationId !== null ? null : $this->source->supportGrantId($request);

        if ($user === null || ($organizationId === null && $grantId === null)) {
            throw new MissingTenantContext;
        }

        $context = $grantId !== null
            ? $this->resolver->enterSupport($user, $grantId)
            : $this->resolver->enterOrganization($user, $organizationId);

        // Break-glass access is fully audited: every page support opens shows in the
        // client's log, and so does every change it tried (refused just below).
        if ($context->isSupport()) {
            $this->audit->record(
                action: 'support.accessed',
                new: array_filter([
                    'method' => $request->method(),
                    'path' => '/'.ltrim($request->path(), '/'),
                    'grant' => $context->supportGrant()->getKey(),
                    'blocked' => $request->isMethodSafe() ? null : true,
                ], fn ($value) => $value !== null),
                actor: $user,
                organizationId: $context->organization()->getKey(),
                partnerId: $context->partner()->getKey(),
            );
        }

        $this->enforceMode($request, $context);

        // A portal member (parent, employee, customer) reaches only the portal's own
        // endpoints, never the organization's structure, members, rules or billing.
        if ($context->membership()->membership_type === MembershipType::Portal && ! $request->is('api/portal', 'api/portal/*')) {
            throw OrganizationAccessDenied::portalOnly();
        }

        // Unless the user picked a language for this request: their profile's, else the
        // organization's (own, inherited or its country's). See LocaleResolver.
        if (! ApplyRequestLocale::wasChosen($request)) {
            foreach ([$request->user()?->locale, $context->locale()] as $locale) {
                if (is_string($locale) && in_array($locale, config('tenancy.supported_locales'), true)) {
                    app()->setLocale($locale);
                    break;
                }
            }
        }

        // After the language is set, so the 404 reads in the organization's language.
        $this->guardRouteOrganization($request, $context);

        return $next($request);
    }

    /**
     * An {organization} in the address must be one this context may see, checked
     * before any validation or controller runs (Phase 8-2). Controllers still load
     * it through Organization::visibleTo; this is the second, central check. An
     * organization that exists elsewhere is a cross-tenant attempt and is logged;
     * the answer is the same 404 as for an id that does not exist.
     */
    private function guardRouteOrganization(Request $request, CurrentContext $context): void
    {
        $id = $request->route('organization');

        if (! is_string($id) || Organization::query()->visibleTo($context)->whereKey($id)->exists()) {
            return;
        }

        if (Str::isUlid($id) && Organization::query()->whereKey($id)->exists()) {
            $this->securityLog->record('tenant.cross_access_attempt', [
                'user_id' => $context->user()?->getKey(),
                'organization_id' => $context->organization()->getKey(),
                'requested_organization_id' => $id,
            ], 'warning');
        }

        throw new OrganizationNotFound;
    }

    /**
     * Read-only contexts refuse every change; export-only contexts reach the
     * export screens only. Exporting stays possible in both (it needs the
     * data.export permission, which support staff never hold). A workspace
     * read-only for an overdue bill can still pay it or move to a free plan.
     */
    private function enforceMode(Request $request, CurrentContext $context): void
    {
        $exporting = $request->is('api/organizations/*/exports', 'api/organizations/*/exports/*');
        $settling = $context->modeReason() === 'payment_overdue'
            && $request->is('api/organizations/*/billing/*');

        if ($context->mode() === CurrentContext::MODE_EXPORT_ONLY && ! $exporting) {
            throw OrganizationAccessDenied::exportOnly();
        }

        if ($context->mode() === CurrentContext::MODE_READ_ONLY && ! $request->isMethodSafe()
            && ! (($exporting || $settling) && ! $context->isSupport())) {
            throw OrganizationAccessDenied::readOnly((string) $context->modeReason());
        }
    }
}
