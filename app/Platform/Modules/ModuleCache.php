<?php

namespace App\Platform\Modules;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Caches resolved module maps per organization. Every tree (root group) has a
 * version number in its cache keys; bumping it invalidates the whole tree at
 * once. Works on any cache store (no tags needed).
 */
class ModuleCache
{
    /**
     * @param  Closure(): array<string, array<string, mixed>>  $compute
     * @return array<string, array<string, mixed>>
     */
    public function remember(string $organizationId, string $rootId, Closure $compute): array
    {
        return Cache::remember(
            "modules:resolved:{$organizationId}:v{$this->version($rootId)}",
            now()->addMinutes((int) config('platform_modules.cache_ttl_minutes')),
            $compute,
        );
    }

    public function version(string $rootId): int
    {
        return (int) Cache::get("modules:version:{$rootId}", 1);
    }

    public function flushTree(?string $rootId): void
    {
        if ($rootId !== null) {
            Cache::forever("modules:version:{$rootId}", $this->version($rootId) + 1);
        }
    }
}
