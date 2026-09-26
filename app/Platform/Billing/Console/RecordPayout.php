<?php

namespace App\Platform\Billing\Console;

use App\Platform\Billing\Services\Payouts;
use App\Platform\Tenancy\Exceptions\TenancyException;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Console\Command;

/**
 * Record a payment sent to a partner: all its payable commissions in one currency.
 *
 *   php artisan billing:payout acme BDT --reference="Transfer 2026-10-05"
 */
class RecordPayout extends Command
{
    protected $signature = 'billing:payout
        {partner : Partner slug or id}
        {currency : ISO currency code}
        {--reference= : Transfer reference (required)}';

    protected $description = 'Record a commission payout to a partner';

    public function handle(Payouts $payouts): int
    {
        $reference = trim((string) $this->option('reference'));
        if (mb_strlen($reference) < 3) {
            $this->error('Give a --reference of at least 3 characters.');

            return self::INVALID;
        }

        $partner = Partner::query()->where('slug', $this->argument('partner'))->orWhere('id', $this->argument('partner'))->first();
        if ($partner === null) {
            $this->error('No partner with that slug or id.');

            return self::INVALID;
        }

        try {
            $payout = $payouts->record($partner, strtoupper((string) $this->argument('currency')), $reference);
        } catch (TenancyException $exception) {
            $this->error($exception->userMessage());

            return self::FAILURE;
        }

        $this->info("Paid {$payout->amount_minor} {$payout->currency_code} to {$partner->name}.");

        return self::SUCCESS;
    }
}
