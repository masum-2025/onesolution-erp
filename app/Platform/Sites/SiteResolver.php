<?php

namespace App\Platform\Sites;

use Illuminate\Support\Facades\Cache;

/**
 * A public site's address -> its organization. "{slug}.{sites domain}"
 * names a site by host; "/s/{slug}" by path; a verified own domain by its
 * host. Unknown names resolve to nothing (never a guess). Lookups are
 * cached briefly.
 */
class SiteResolver
{
    private const CACHE_SECONDS = 300;

    public const SLUG_PATTERN = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /** The slug in "{slug}.{sites domain}", or null for any other host. */
    public function slugFromHost(string $host): ?string
    {
        $host = strtolower($host);
        $suffix = '.'.strtolower((string) config('sites.domain'));
        if ($suffix === '.' || ! str_ends_with($host, $suffix)) {
            return null;
        }
        $slug = substr($host, 0, -strlen($suffix));

        return preg_match('/^'.self::SLUG_PATTERN.'$/', $slug) ? $slug : null;
    }

    public function find(string $module, string $slug): ?SiteAddress
    {
        $id = Cache::remember($this->key($slug), self::CACHE_SECONDS, fn () => SiteAddress::query()->where('slug', strtolower($slug))->value('id') ?? false);
        if ($id === false) {
            return null;
        }

        return SiteAddress::query()->with('organization')->whereKey($id)->where('module', $module)->first();
    }

    /** The slug of the site whose verified own domain this host is, or null. */
    public function slugForDomain(string $host): ?string
    {
        $slug = Cache::remember($this->domainKey($host), self::CACHE_SECONDS, fn () => SiteAddress::query()
            ->where('domain', strtolower($host))->whereNotNull('domain_verified_at')->value('slug') ?? false);

        return $slug === false ? null : $slug;
    }

    public function forget(string $slug): void
    {
        Cache::forget($this->key(strtolower($slug)));
    }

    public function forgetDomain(?string $host): void
    {
        if ($host !== null) {
            Cache::forget($this->domainKey($host));
        }
    }

    /** Where the site lives for visitors: its verified own domain (https), else its address on the sites domain. */
    public function primaryUrl(SiteAddress $address): string
    {
        return $address->domain !== null && $address->domain_verified_at !== null ? "https://{$address->domain}" : $this->url($address->slug);
    }

    /** The site's address on the sites domain. */
    public function url(string $slug): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return "{$scheme}://{$slug}.".config('sites.domain');
    }

    private function domainKey(string $host): string
    {
        return 'sites:domain:'.sha1(strtolower($host));
    }

    private function key(string $slug): string
    {
        return 'sites:slug:'.sha1($slug);
    }
}
