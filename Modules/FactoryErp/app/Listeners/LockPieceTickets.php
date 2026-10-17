<?php

namespace Modules\FactoryErp\Listeners;

use App\Platform\Tenancy\Models\Organization;
use Modules\FactoryErp\Services\PieceRates;
use Modules\Payroll\Events\PayrollApproved;

/**
 * A month's payroll was approved: the piece tickets it paid stay as they are.
 */
class LockPieceTickets
{
    public function __construct(private PieceRates $pieceRates) {}

    public function handle(PayrollApproved $event): void
    {
        $company = Organization::query()->find($event->companyId);
        if ($company !== null) {
            $this->pieceRates->lock($company, $event->period);
        }
    }
}
