<?php

namespace App\Platform\Addons\Console;

use App\Platform\Addons\AddonPriceBook;
use App\Platform\Addons\Models\AddonPrice;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Tenancy\Models\Partner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Set a platform add-on price: what clients pay (list) or what a wholesale
 * partner pays us (wholesale), for every partner or one partner's deal.
 *
 *   php artisan addons:price projects BDT 80000 --kind=wholesale --partner=acme --reason="2027 contract"
 */
class SetAddonPrice extends Command
{
    protected $signature = 'addons:price
        {module : Module key}
        {currency : ISO currency code}
        {amount : Per month, in minor units}
        {--kind=list : list or wholesale}
        {--partner= : Wholesale only: this partner (slug or id); default: every partner}
        {--from= : YYYY-MM-DD (default: today)}
        {--reason= : Why (required)}';

    protected $description = 'Set a platform price for an add-on module';

    public function handle(AddonPriceBook $book, ModuleRegistry $registry): int
    {
        $module = (string) $this->argument('module');
        $reason = trim((string) $this->option('reason'));
        $currency = strtoupper((string) $this->argument('currency'));
        $amount = (string) $this->argument('amount');
        $kind = (string) $this->option('kind');
        $from = $this->option('from');

        $problem = match (true) {
            ! $registry->has($module) || ! $registry->get($module)->sellable => 'Unknown module, or not sold as an add-on.',
            ! preg_match('/^[A-Z]{3}$/', $currency) => 'The currency must be an ISO code like USD.',
            ! ctype_digit($amount) => 'The amount must be a whole number of minor units.',
            ! in_array($kind, [AddonPrice::LIST, AddonPrice::WHOLESALE], true) => 'The kind must be list or wholesale.',
            // A partner sets its own list price in its console.
            $kind === AddonPrice::LIST && $this->option('partner') !== null => 'A partner sets its own list price in the partner console.',
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
        $book->set($partner, $kind, $module, $currency, (int) $amount, $start, reason: $reason);

        $this->info(sprintf('%s, %s (%s): %s %s from %s.', $partner?->name ?? 'All partners', $module, $kind, $amount, $currency, $start->toDateString()));

        return self::SUCCESS;
    }
}
