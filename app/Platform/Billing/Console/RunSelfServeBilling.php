<?php

namespace App\Platform\Billing\Console;

use App\Platform\Billing\SelfServe\Dunning;
use App\Platform\Billing\SelfServe\Renewals;
use App\Platform\Billing\SelfServe\Trials;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The self-serve day (scheduled hourly, safe to repeat): trial reminders
 * and ends, renewal invoices, overdue reminders and read-only.
 *
 *   php artisan billing:self-serve
 */
class RunSelfServeBilling extends Command
{
    protected $signature = 'billing:self-serve';

    protected $description = 'Trials, renewal invoices and overdue reminders of self-serve accounts';

    public function handle(Trials $trials, Renewals $renewals, Dunning $dunning): int
    {
        $now = CarbonImmutable::now();

        $trial = $trials->sweep($now);
        $renewal = $renewals->run($now);
        $overdue = $dunning->run($now);

        $this->info(sprintf(
            'Trials: %d reminded, %d ended. Renewals: %d issued, %d moved to the free plan. Overdue: %d reminded, %d read-only, %d restored.',
            $trial['reminded'], $trial['ended'],
            count($renewal['issued']), $renewal['moved_to_free'],
            $overdue['reminded'], $overdue['restricted'], $overdue['restored'],
        ));

        foreach ($renewal['issued'] as $number) {
            $this->line("  issued {$number}");
        }

        return self::SUCCESS;
    }
}
