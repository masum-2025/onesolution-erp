<?php

namespace App\Platform\Payments\Console;

use App\Platform\Payments\Services\PaymentReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Asks the gateways about open payments and gives up the expired ones
 * (scheduled every few minutes, safe to repeat).
 *
 *   php artisan payments:reconcile
 */
class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Check open online payments with their gateway and expire abandoned ones';

    public function handle(PaymentReconciler $reconciler): int
    {
        $counts = $reconciler->sweep(CarbonImmutable::now());

        $this->info(sprintf('%d checked, %d confirmed, %d expired.', $counts['checked'], $counts['succeeded'], $counts['expired']));

        return self::SUCCESS;
    }
}
