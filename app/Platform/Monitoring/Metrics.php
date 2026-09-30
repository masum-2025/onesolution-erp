<?php

namespace App\Platform\Monitoring;

use Illuminate\Support\Facades\Cache;

/**
 * Small counters for the health report (Phase 9-2), in hourly buckets in
 * the cache (shared by all servers with Redis). "Recent" = this hour and the
 * one before.
 */
class Metrics
{
    private const BUCKET_SECONDS = 3600;

    public function count(string $name, int $by = 1): void
    {
        $key = $this->key($name, intdiv(now()->getTimestamp(), self::BUCKET_SECONDS));
        Cache::add($key, 0, self::BUCKET_SECONDS * 3);
        Cache::increment($key, $by);
    }

    public function recent(string $name): int
    {
        $bucket = intdiv(now()->getTimestamp(), self::BUCKET_SECONDS);

        return (int) Cache::get($this->key($name, $bucket), 0) + (int) Cache::get($this->key($name, $bucket - 1), 0);
    }

    private function key(string $name, int $bucket): string
    {
        return "metrics:{$name}:{$bucket}";
    }
}
