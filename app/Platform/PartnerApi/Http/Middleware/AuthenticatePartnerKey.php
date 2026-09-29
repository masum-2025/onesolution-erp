<?php

namespace App\Platform\PartnerApi\Http\Middleware;

use App\Platform\PartnerApi\Exceptions\ApiException;
use App\Platform\PartnerApi\Services\ApiKeyService;
use App\Platform\Tenancy\Context\ContextResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Partner API: the bearer key decides the partner and the person the work is
 * done for (the key's creator, an active owner). Nothing else (cookies,
 * session tokens) is accepted here, and nothing here reaches client
 * business data: the context is the partner console's.
 */
class AuthenticatePartnerKey
{
    public function __construct(private ApiKeyService $keys, private ContextResolver $resolver) {}

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

        return $next($request);
    }
}
