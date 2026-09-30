<?php

namespace App\Platform\Analytics\Console;

use App\Platform\Analytics\AnalyticsExport;
use App\Platform\Analytics\Jobs\ExportAnalytics;
use Illuminate\Console\Command;

/**
 * Queue the analytics export (scheduled), or run it now with --now.
 */
class ExportAnalyticsCommand extends Command
{
    protected $signature = 'analytics:export {--now : Run in this process instead of queueing}';

    protected $description = 'Send new platform rows to the analytics store (ANALYTICS_DRIVER)';

    public function handle(AnalyticsExport $export): int
    {
        if (config('analytics.driver') === 'none') {
            $this->line('No analytics store is configured (ANALYTICS_DRIVER=none). Nothing to do.');

            return self::SUCCESS;
        }

        if (! $this->option('now')) {
            ExportAnalytics::dispatch();
            $this->info('Analytics export queued.');

            return self::SUCCESS;
        }

        foreach ($export->run() as $dataset => $rows) {
            $this->line("{$dataset}: {$rows} rows sent.");
        }

        return self::SUCCESS;
    }
}
