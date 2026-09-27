<?php

namespace App\Platform\Billing;

use App\Platform\Billing\Console\IssueCreditNote;
use App\Platform\Billing\Console\MarkInvoicePaid;
use App\Platform\Billing\Console\RecordPayout;
use App\Platform\Billing\Console\RunBilling;
use App\Platform\Billing\Console\RunSelfServeBilling;
use App\Platform\Billing\SelfServe\OverdueRestrictions;
use App\Platform\Tenancy\Contracts\WorkspaceRestrictions;
use App\Platform\Billing\Console\SetWholesalePrice;
use App\Platform\Billing\Console\SyncWholesalePrices;
use Illuminate\Support\ServiceProvider;

/**
 * Partner plans, invoices, credit notes, commissions and payouts (Phase 5B-3),
 * and self-serve plans: checkout, trials, renewals, overdue (Phase 5C-2).
 * Platform operators act through artisan commands; partners and clients read
 * through the API.
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A self-serve account with an overdue bill becomes read-only (Phase 5C-2).
        $this->app->bind(WorkspaceRestrictions::class, OverdueRestrictions::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RunBilling::class,
                RunSelfServeBilling::class,
                MarkInvoicePaid::class,
                IssueCreditNote::class,
                RecordPayout::class,
                SetWholesalePrice::class,
                SyncWholesalePrices::class,
            ]);
        }
    }
}
