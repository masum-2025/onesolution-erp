<?php

namespace App\Platform\Analytics;

/**
 * The connection for heavy reads (Phase 10-3): a reporting replica when
 * DB_REPORTING_HOST is set (config database.reporting), else the main one.
 * Only platform data is read through it; business data of a client in its
 * own database is read through TenantDatabases.
 */
class ReportingDatabase
{
    public const CONNECTION = 'reporting';

    public function isConfigured(): bool
    {
        return (array) config('database.reporting', []) !== [];
    }

    public function connection(): string
    {
        if (! $this->isConfigured()) {
            return (string) config('database.default');
        }

        if (config('database.connections.'.self::CONNECTION) === null) {
            $base = (array) config('database.connections.'.config('database.default'));
            // A replica of its own: never the primary's read/write split or URL.
            unset($base['read'], $base['write'], $base['sticky']);
            config(['database.connections.'.self::CONNECTION => [...$base, 'url' => null, ...(array) config('database.reporting')]]);
        }

        return self::CONNECTION;
    }
}
