<?php

namespace App\Platform\Transfers;

use App\Platform\Legal\Console\SyncLegalDocuments;
use App\Platform\Transfers\Console\MoveClients;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Data ownership and exit (Phase 5B-4): clients moving between partners,
 * and the legal documents clients accept.
 */
class TransfersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Transfer codes must not be guessable by trying: a few tries per hour.
        RateLimiter::for('client-transfer', fn (Request $request) => Limit::perHour(10)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([MoveClients::class, SyncLegalDocuments::class]);
        }
    }
}
