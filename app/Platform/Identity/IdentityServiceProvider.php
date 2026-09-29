<?php

namespace App\Platform\Identity;

use App\Platform\Identity\BotChecks\NoBotCheck;
use App\Platform\Identity\BotChecks\TurnstileBotCheck;
use App\Platform\Identity\Console\EraseDueAccounts;
use App\Platform\Identity\Contracts\BotCheck;
use App\Platform\Identity\Services\TwoFactorRequirement;
use App\Platform\Tenancy\Contracts\SignInRequirements;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Self-serve identity (Phase 5C-1): sign-up, one-time codes, recovery and
 * the person's own account. Burst limits per network here; hourly limits
 * per address and network are rules (see OtpService).
 */
class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BotCheck::class, function () {
            $driver = (string) config('identity.bot_check.driver');

            if ($driver === 'turnstile') {
                return new TurnstileBotCheck(
                    (string) config('identity.bot_check.turnstile.site_key'),
                    (string) config('identity.bot_check.turnstile.secret'),
                );
            }

            if ($this->app->isProduction()) {
                Log::error('No bot check configured: self-serve sign-up and recovery are closed. Set BOT_CHECK_DRIVER=turnstile.');

                return new NoBotCheck(open: false);
            }

            return new NoBotCheck;
        });

        // Two-step sign-in (Phase 8-1): per request, so /api/me can read the setup due date.
        $this->app->scoped(TwoFactorRequirement::class);
        $this->app->bind(SignInRequirements::class, TwoFactorRequirement::class);
    }

    public function boot(): void
    {
        // Starting a sign-up or recovery: a few per minute from one network.
        RateLimiter::for('identity-start', fn (Request $request) => Limit::perMinute(5)->by('identity-start:'.$request->ip()));

        // Entering or resending codes: enough for typos, too few to guess six digits.
        RateLimiter::for('identity-code', fn (Request $request) => Limit::perMinute(10)->by('identity-code:'.($request->user()?->getKey() ?? $request->ip())));

        // "Download my data" (Phase 5C-3): a whole file each time, a few per hour.
        RateLimiter::for('my-data', fn (Request $request) => Limit::perHour(5)->by('my-data:'.($request->user()?->getKey() ?? $request->ip())));

        // Second steps (Phase 8-1): each waiting sign-in also ends after a few wrong tries.
        RateLimiter::for('two-factor', fn (Request $request) => [
            Limit::perMinute(10)->by('two-factor:ip:'.$request->ip()),
            Limit::perMinute(6)->by('two-factor:who:'.($request->user()?->getKey() ?? $request->session()?->getId() ?? $request->ip())),
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([EraseDueAccounts::class]);
        }
    }
}
