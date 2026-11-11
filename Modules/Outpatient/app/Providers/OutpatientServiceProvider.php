<?php

namespace Modules\Outpatient\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Outpatient\Events\SerialBooked;
use Modules\Outpatient\Listeners\SmsWhenSerialBooked;

/**
 * Outpatient: Doctors' chambers: weekly schedules, serials with the fee on the patient's bill, free follow-ups, the waiting-room screen and the doctor's own patient list.
 */
class OutpatientServiceProvider extends ServiceProvider
{
    /** Writes a person may send a minute. */
    private const WRITES_PER_MINUTE = 60;

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'outpatient');

        RateLimiter::for('outpatient-writes', fn (Request $request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by('outpatient-writes:'.($request->user()?->getKey() ?? $request->ip())));

        // A new serial may be texted to the patient (rule outpatient.serial_sms).
        Event::listen(SerialBooked::class, SmsWhenSerialBooked::class);

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
