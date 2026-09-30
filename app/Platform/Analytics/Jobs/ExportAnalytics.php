<?php

namespace App\Platform\Analytics\Jobs;

use App\Platform\Analytics\AnalyticsExport;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Feeds the analytics store in the background (one at a time; a run that
 * fails is retried and continues from the cursor).
 */
class ExportAnalytics implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public int $uniqueFor = 900;

    public function handle(AnalyticsExport $export): void
    {
        $export->run();
    }
}
