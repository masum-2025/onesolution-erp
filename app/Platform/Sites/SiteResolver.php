<?php

namespace App\Platform\Sites;

use Illuminate\Support\Facades\Cache;

/**
 * A public site's address -> its organization. "{slug}.{sites domain}"
 * names a site by host; "/s/{slug}" by path. Unknown slugs resolve to
 * nothing (never a guess). Lookups are cached briefly.
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

    public function forget(string $slug): void
    {
        Cache::forget($this->key(strtolower($slug)));
    }

    /** The site's address on the sites domain. */
    public function url(string $slug): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return "{$scheme}://{$slug}.".config('sites.domain');
    }

    private function key(string $slug): string
    {
        return 'sites:slug:'.sha1($slug);
    }
}
