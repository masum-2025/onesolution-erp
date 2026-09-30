<?php

namespace App\Platform\Analytics;

use App\Platform\Analytics\Console\ExportAnalyticsCommand;
use App\Platform\Analytics\Contracts\AnalyticsSink;
use App\Platform\Analytics\Sinks\ClickHouseSink;
use App\Platform\Analytics\Sinks\JsonLinesSink;
use App\Platform\Analytics\Sinks\NullSink;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Reporting replica and analytics export (Phase 10-3).
 */
class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AnalyticsSink::class, fn ($app) => match (config('analytics.driver')) {
            'none', null => $app->make(NullSink::class),
            'jsonl' => $app->make(JsonLinesSink::class),
            'clickhouse' => $app->make(ClickHouseSink::class),
            default => throw new InvalidArgumentException('Unknown analytics driver: '.config('analytics.driver')),
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExportAnalyticsCommand::class]);
        }
    }
}
