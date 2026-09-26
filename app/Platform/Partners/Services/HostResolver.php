<?php

namespace App\Platform\Partners\Services;

use App\Platform\Partners\Enums\DomainStatus;
use App\Platform\Partners\Models\PartnerDomain;
use Illuminate\Support\Facades\Cache;

/**
 * Host name -> platform, or an active partner domain, or nothing. Never
 * guesses: an unknown or unverified host resolves to nothing.
 */
class HostResolver
{
    private const CACHE_SECONDS = 300;

    /**
     * @return list<string>
     */
    public function platformHosts(): array
    {
        $configured = array_map('trim', explode(',', (string) config('branding.platform_hosts')));
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return array_values(array_unique(array_filter(array_map('strtolower', [...$configured, $appHost]))));
    }

    public function isPlatformHost(string $host): bool
    {
        return in_array(strtolower($host), $this->platformHosts(), true);
    }

    public function activeDomain(string $host): ?PartnerDomain
    {
        $host = strtolower($host);

        $id = Cache::remember($this->cacheKey($host), self::CACHE_SECONDS, fn () => PartnerDomain::query()
            ->where('host', $host)
            ->where('status', DomainStatus::Active)
            ->value('id') ?? false);

        if ($id === false) {
            return null;
        }

        // Re-read the row: a cached id never outlives a disabled domain.
        return PartnerDomain::query()->with(['partner', 'organization'])->whereKey($id)->where('status', DomainStatus::Active)->first();
    }

    public function forget(string $host): void
    {
        Cache::forget($this->cacheKey(strtolower($host)));
    }

    private function cacheKey(string $host): string
    {
        return 'hosts:'.sha1($host);
    }
}
