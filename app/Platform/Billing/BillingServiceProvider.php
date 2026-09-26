<?php

namespace App\Platform\Billing;

use App\Platform\Billing\Console\IssueCreditNote;
use App\Platform\Billing\Console\MarkInvoicePaid;
use App\Platform\Billing\Console\RecordPayout;
use App\Platform\Billing\Console\RunBilling;
use App\Platform\Billing\Console\SetWholesalePrice;
use App\Platform\Billing\Console\SyncWholesalePrices;
use Illuminate\Support\ServiceProvider;

/**
 * Partner plans, invoices, credit notes, commissions and payouts (Phase 5B-3).
 * Platform operators act through artisan commands; partners and clients read
 * through the API.
 */
class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RunBilling::class,
                MarkInvoicePaid::class,
                IssueCreditNote::class,
                RecordPayout::class,
                SetWholesalePrice::class,
                SyncWholesalePrices::class,
            ]);
        }
    }
}
