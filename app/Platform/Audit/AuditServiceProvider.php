<?php

namespace App\Platform\Audit;

use App\Platform\Audit\Console\PruneAudit;
use App\Platform\Audit\Console\ShipAudit;
use App\Platform\Audit\Shipping\Contracts\AuditShipper;
use App\Platform\Audit\Shipping\LogShipper;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Audit log extras (Phase 9-1): external shipping, retention, and the
 * advanced_audit reports and exports.
 */
class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuditShipper::class, fn ($app) => match (config('audit.shipping.driver')) {
            'log' => $app->make(LogShipper::class),
            default => throw new InvalidArgumentException('Unknown audit shipping driver: '.config('audit.shipping.driver')),
        });
    }

    public function boot(): void
    {
        // Reports read many entries: a few per minute per person.
        RateLimiter::for('audit-report', fn (Request $request) => Limit::perMinute(10)
            ->by('audit-report:'.($request->user()?->getKey() ?? $request->ip())));

        if ($this->app->runningInConsole()) {
            $this->commands([PruneAudit::class, ShipAudit::class]);
        }
    }
}
