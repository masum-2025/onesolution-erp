<?php

namespace App\Platform\Payments\Console;

use App\Platform\Payments\Services\MerchantAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Applies merchant account changes whose single-approver wait is over, after
 * checking them with the gateway once more (scheduled, safe to repeat).
 *
 *   php artisan payments:apply-merchant-changes
 */
class ApplyMerchantChanges extends Command
{
    protected $signature = 'payments:apply-merchant-changes';

    protected $description = 'Apply payment gateway account changes whose waiting time is over';

    public function handle(MerchantAccounts $accounts): int
    {
        $counts = $accounts->applyDue(CarbonImmutable::now());

        $this->info(sprintf('%d applied, %d not accepted by the gateway.', $counts['applied'], $counts['failed']));

        return self::SUCCESS;
    }
}
