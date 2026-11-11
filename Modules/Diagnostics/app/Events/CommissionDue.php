<?php

namespace Modules\Diagnostics\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An order earned its referring doctor commission (manifest event "diagnostics.commission_due"). Ids only.
 */
class CommissionDue
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $commissionId, public string $referrerId, public string $orderId,
    ) {}

    public function name(): string
    {
        return 'diagnostics.commission_due';
    }
}
