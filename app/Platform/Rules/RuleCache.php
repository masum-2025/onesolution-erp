<?php

namespace App\Platform\Rules;

use App\Platform\Rules\Enums\RuleScope;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Caches resolved rule maps per chain. Cache keys carry three version numbers:
 * global (platform / plan / role / user changes), partner, and tree (root
 * group). A change bumps the matching version, invalidating every descendant.
 */
class RuleCache
{
    /**
     * @param  Closure(): array{0: array<string, array<string, mixed>>, 1: int|null}  $compute  Returns [map, seconds until the next effective-date boundary].
     * @return array{map: array<string, array<string, mixed>>, valid_until: int} valid_until is a Unix timestamp.
     */
    public function remember(RuleContext $context, Closure $compute): array
    {
        $key = 'rules:resolved:'.$context->fingerprint().':'.$this->versionTag($context);

        $cached = Cache::get($key);
        if (is_array($cached) && ($cached['valid_until'] ?? 0) > now()->getTimestamp()) {
            return $cached;
        }

        [$map, $secondsToBoundary] = $compute();
        $ttl = (int) config('platform_rules.cache_ttl_minutes') * 60;

        if ($secondsToBoundary !== null) {
            $ttl = max(1, min($ttl, $secondsToBoundary));
        }

        // The expiry travels with the data, so in-memory copies also stop at
        // the moment a future-dated value takes effect.
        $payload = ['map' => $map, 'valid_until' => now()->getTimestamp() + $ttl];
        Cache::put($key, $payload, $ttl);

        return $payload;
    }

    public function versionTag(RuleContext $context): string
    {
        return implode('.', [
            $this->version('global'),
            $context->partnerId ? $this->version('partner:'.$context->partnerId) : 0,
            $context->rootOrganizationId ? $this->version('root:'.$context->rootOrganizationId) : 0,
        ]);
    }

    /**
     * Invalidate every chain that includes this scope.
     */
    public function flush(RuleScope $scope, ?string $scopeId, ?string $rootOrganizationId = null): void
    {
        match (true) {
            $scope === RuleScope::Partner => $this->bump('partner:'.$scopeId),
            $scope->isOrganizationLevel() && $rootOrganizationId !== null => $this->bump('root:'.$rootOrganizationId),
            default => $this->bump('global'),
        };
    }

    public function flushTree(?string $rootOrganizationId): void
    {
        if ($rootOrganizationId !== null) {
            $this->bump('root:'.$rootOrganizationId);
        }
    }

    private function version(string $name): int
    {
        return (int) Cache::get('rules:version:'.$name, 1);
    }

    private function bump(string $name): void
    {
        Cache::forever('rules:version:'.$name, $this->version($name) + 1);
    }
}
