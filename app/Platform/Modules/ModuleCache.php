<?php

namespace App\Platform\Modules;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Caches resolved module maps per organization. Every tree (root group) has a
 * version number in its cache keys; bumping it invalidates the whole tree at
 * once. Works on any cache store (no tags needed).
 *
 * The keys also carry a fingerprint of the installed modules, so a module
 * added (or removed) with a release never meets a map cached before it: such
 * a map would lack the new module, and every lookup of it would fail.
 */
class ModuleCache
{
    public function __construct(private ModuleRegistry $registry) {}

    /**
     * @param  Closure(): array<string, array<string, mixed>>  $compute
     * @return array<string, array<string, mixed>>
     */
    public function remember(string $organizationId, string $rootId, Closure $compute): array
    {
        return Cache::remember(
            "modules:resolved:{$organizationId}:v{$this->version($rootId)}:m{$this->fingerprint()}",
            now()->addMinutes((int) config('platform_modules.cache_ttl_minutes')),
            $compute,
        );
    }

    public function version(string $rootId): int
    {
        return (int) Cache::get("modules:version:{$rootId}", 1);
    }

    /** A short mark of which modules are installed (their keys, in order). */
    public function fingerprint(): string
    {
        return substr(hash('sha256', implode(',', $this->registry->keys())), 0, 12);
    }

    public function flushTree(?string $rootId): void
    {
        if ($rootId !== null) {
            Cache::forever("modules:version:{$rootId}", $this->version($rootId) + 1);
        }
    }
}
