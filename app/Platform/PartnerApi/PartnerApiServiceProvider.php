<?php

namespace App\Platform\PartnerApi;

use App\Platform\PartnerApi\Http\Middleware\ApiIdempotency;
use App\Platform\PartnerApi\Http\Middleware\AuthenticatePartnerKey;
use App\Platform\PartnerApi\Http\Middleware\RequireApiScope;
use App\Platform\PartnerApi\Models\PartnerApiKey;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * The partner API (Phase 5B-5): keys, scopes, per-key rate limits and
 * idempotent writes.
 */
class PartnerApiServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        $router->aliasMiddleware('partner.key', AuthenticatePartnerKey::class);
        $router->aliasMiddleware('api.scope', RequireApiScope::class);
        $router->aliasMiddleware('api.idempotent', ApiIdempotency::class);

        // Per key, at the partner's rate (partners.api_rate_per_minute). Throttling runs
        // before the key is checked, so the limiter finds the key by its prefix itself;
        // the count is kept per full token, so a wrong secret cannot use up a real key's quota.
        RateLimiter::for('partner-api', function (Request $request) {
            $token = (string) $request->bearerToken();
            $key = str_starts_with($token, 'osk_') ? PartnerApiKey::query()->where('prefix', substr($token, 0, 12))->first() : null;
            $partner = $key === null ? null : Partner::query()->find($key->partner_id);

            if ($partner === null) {
                return Limit::perMinute(30)->by('ip:'.$request->ip());
            }

            $rate = (int) app(RuleResolver::class)->get('partners.api_rate_per_minute', app(RuleContextFactory::class)->forPartner($partner));

            return Limit::perMinute(max(1, $rate))->by('key:'.hash('sha256', $token));
        });
    }
}
