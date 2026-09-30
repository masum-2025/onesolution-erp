<?php

namespace App\Platform\PartnerApi\Http\Middleware;

use App\Platform\PartnerApi\Exceptions\ApiException;
use App\Platform\PartnerApi\Services\ApiKeyService;
use App\Platform\Security\SecurityLog;
use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Partner API: the bearer key decides the partner and the person the work is
 * done for (the key's creator, an active owner). Nothing else (cookies,
 * session tokens) is accepted here, and nothing here reaches client
 * business data: the context is the partner console's.
 */
class AuthenticatePartnerKey
{
    public function __construct(
        private ApiKeyService $keys,
        private ContextResolver $resolver,
        private SecurityLog $securityLog,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $found = $this->keys->authenticate((string) $request->bearerToken());
        if ($found === null) {
            throw ApiException::invalidKey();
        }

        ['key' => $key, 'creator' => $creator] = $found;

        $this->resolver->enterPartner($creator, $key->partner_id, checkSignIn: false);
        $request->setUserResolver(fn () => $creator);
        // Read by scope checks, rate limits, idempotency and the audit log.
        $request->attributes->set('partner_api_key', $key);

        $this->guardRouteClient($request, $key->partner_id, $key->getKey());

        return $next($request);
    }

    /**
     * A client in the address must be one of this partner's client accounts, checked
     * before scopes, validation or the controller (Phase 8-2). Another partner's
     * client is logged and gets the same 404 as a missing one.
     */
    private function guardRouteClient(Request $request, string $partnerId, string $keyId): void
    {
        $id = $request->route('client');

        if (! is_string($id)) {
            return;
        }

        $client = Str::isUlid($id) ? Organization::query()->whereKey($id)->first(['id', 'partner_id', 'parent_id']) : null;

        if ($client !== null && $client->partner_id === $partnerId && $client->parent_id === null) {
            return;
        }

        if ($client !== null && $client->partner_id !== $partnerId) {
            $this->securityLog->record('tenant.cross_access_attempt', [
                'partner_id' => $partnerId,
                'api_key_id' => $keyId,
                'requested_organization_id' => $id,
            ], 'warning');
        }

        throw ApiException::notFound();
    }
}
