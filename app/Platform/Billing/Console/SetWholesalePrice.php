<?php

namespace App\Platform\Billing\Console;

use App\Platform\Billing\Models\WholesalePrice;
use App\Platform\Billing\Services\WholesalePriceBook;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Tenancy\Models\Partner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Set a wholesale price, for every partner or one partner's deal. The old
 * price stays on record; the new one applies from --from (default today).
 *
 *   php artisan billing:wholesale-price business USD 2500 --unit=per_client --partner=acme --reason="2027 contract"
 */
class SetWholesalePrice extends Command
{
    protected $signature = 'billing:wholesale-price
        {plan : Plan key}
        {currency : ISO currency code}
        {amount : Per month, in minor units}
        {--unit=per_client : per_client or per_seat}
        {--partner= : Only this partner (slug or id); default: every partner}
        {--from= : YYYY-MM-DD (default: today)}
        {--reason= : Why (required)}';

    protected $description = 'Set a wholesale price for a plan';

    public function handle(WholesalePriceBook $book, PlanCatalog $plans): int
    {
        $reason = trim((string) $this->option('reason'));
        $currency = strtoupper((string) $this->argument('currency'));
        $amount = (string) $this->argument('amount');
        $unit = (string) $this->option('unit');
        $from = $this->option('from');

        $problem = match (true) {
            ! $plans->has((string) $this->argument('plan')) => 'Unknown plan.',
            ! preg_match('/^[A-Z]{3}$/', $currency) => 'The currency must be an ISO code like USD.',
            ! ctype_digit($amount) => 'The amount must be a whole number of minor units.',
            ! in_array($unit, WholesalePrice::UNITS, true) => 'The unit must be per_client or per_seat.',
            mb_strlen($reason) < 5 => 'Give a --reason of at least 5 characters.',
            $from !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $from) => 'Give --from as YYYY-MM-DD.',
            default => null,
        };

        if ($problem !== null) {
            $this->error($problem);

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

        $start = $from === null ? CarbonImmutable::today() : CarbonImmutable::createFromFormat('!Y-m-d', (string) $from);
        $book->set($partner, (string) $this->argument('plan'), $currency, $unit, (int) $amount, $start, reason: $reason);

        $this->info(sprintf('%s, %s: %s %s %s from %s.', $partner?->name ?? 'All partners', $this->argument('plan'), $amount, $currency, $unit, $start->toDateString()));

        return self::SUCCESS;
    }
}
