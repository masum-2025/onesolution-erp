<?php

namespace App\Platform\Billing\Console;

use App\Platform\Billing\Services\BillingRun;
use App\Platform\Tenancy\Models\Partner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The monthly billing run (scheduled on the 1st). Safe to repeat.
 *
 *   php artisan billing:run                  (this month)
 *   php artisan billing:run --month=2026-10 --partner=acme
 */
class RunBilling extends Command
{
    protected $signature = 'billing:run
        {--month= : YYYY-MM (default: this month, UTC)}
        {--partner= : Only this partner (slug or id)}';

    protected $description = 'Issue the month\'s invoices to wholesale partners and to clients';

    public function handle(BillingRun $run): int
    {
        $month = (string) $this->option('month');
        if ($month !== '' && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $this->error('Give --month as YYYY-MM.');

            return self::INVALID;
        }

        $partner = null;
        if ($this->option('partner') !== null) {
            $partner = Partner::query()->where('slug', $this->option('partner'))->orWhere('id', $this->option('partner'))->first();
            if ($partner === null) {
                $this->error('No partner with that slug or id.');

                return self::INVALID;
            }
        }

        $start = $month === '' ? CarbonImmutable::now('UTC') : CarbonImmutable::createFromFormat('!Y-m', $month, 'UTC');
        $result = $run->run($start, $partner);

        $this->info(sprintf('%s: %d issued, %d already issued, %d could not be billed.', $start->format('Y-m'), count($result->issued), $result->already, count($result->skipped)));
        foreach ($result->issued as $number) {
            $this->line("  issued {$number}");
        }
        foreach ($result->skipped as $skipped) {
            $this->warn("  {$skipped['partner']}: {$skipped['reason']}");
        }

        return $result->skipped === [] ? self::SUCCESS : self::FAILURE;
    }
}
