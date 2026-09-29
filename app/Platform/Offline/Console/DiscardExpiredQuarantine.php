<?php

namespace App\Platform\Offline\Console;

use App\Platform\Offline\Services\QuarantineService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Held offline changes nobody decided on in time are discarded (rule
 * offline_mode.quarantine_days). Scheduled daily, safe to repeat.
 *
 *   php artisan offline:discard-expired
 */
class DiscardExpiredQuarantine extends Command
{
    protected $signature = 'offline:discard-expired';

    protected $description = 'Discard held offline changes whose decision time has passed';

    public function handle(QuarantineService $quarantine): int
    {
        $this->info(sprintf('%d held changes discarded.', $quarantine->discardExpired(CarbonImmutable::now())));

        return self::SUCCESS;
    }
}
