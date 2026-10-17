<?php

namespace Modules\FactoryErp\Payroll;

use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\FactoryErp\Services\PieceRates;
use Modules\Payroll\Contracts\EarningSource;

/**
 * Piece-rate pay into Payroll (its EarningSource contract): each operator's
 * tickets of the run's days, one taxable "Piece rate" line.
 */
class PieceRateEarnings implements EarningSource
{
    public function __construct(private PieceRates $pieceRates) {}

    public function earnings(Organization $company, array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->pieceRates->earnings($company, $employeeIds, $from, $to);
    }
}
