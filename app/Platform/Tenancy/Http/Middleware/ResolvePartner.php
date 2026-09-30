<?php

namespace App\Platform\Tenancy\Http\Middleware;

use App\Platform\Security\SecurityLog;
use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Context\ContextSource;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Exceptions\OrganizationAccessDenied;
use App\Platform\Tenancy\Exceptions\OrganizationNotFound;
use App\Platform\Tenancy\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the partner for partner-console routes, from the token or the browser
 * session only (ContextSource). Runs after auth:sanctum.
 */
class ResolvePartner
{
    /** Route parameters that name one of the partner's client organizations. */
    private const CLIENT_PARAMETERS = ['organization', 'client'];

    public function __construct(
        private ContextResolver $resolver,
        private ContextSource $source,
        private SecurityLog $securityLog,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $partnerId = $user === null ? null : $this->source->partnerId($request);

        if ($user === null || $partnerId === null) {
            throw OrganizationAccessDenied::noPartnerAccess();
        }

        $context = $this->resolver->enterPartner($user, $partnerId);

        $this->guardRouteClients($request, $context);

        return $next($request);
    }

    /**
     * A client named in the address must belong to this partner, checked before
     * any validation or controller runs (Phase 8-2); controllers scope their own
     * lookups too. Another partner's client is logged and gets the same 404 as
     * an id that does not exist.
     */
    private function guardRouteClients(Request $request, CurrentContext $context): void
    {
        foreach (self::CLIENT_PARAMETERS as $parameter) {
            $id = $request->route($parameter);

            if (! is_string($id)) {
                continue;
            }

            $owner = Str::isUlid($id) ? Organization::query()->whereKey($id)->value('partner_id') : null;

            if ($owner === $context->partner()->getKey()) {
                continue;
            }

            if ($owner !== null) {
                $this->securityLog->record('tenant.cross_access_attempt', [
                    'user_id' => $context->user()?->getKey(),
                    'partner_id' => $context->partner()->getKey(),
                    'requested_organization_id' => $id,
                ], 'warning');
            }

            throw new OrganizationNotFound;
        }
    }
}
