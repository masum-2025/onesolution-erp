<?php

namespace Modules\Diagnostics\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tests were ordered for a patient (manifest event "diagnostics.ordered"). Ids only.
 */
class TestsOrdered
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $orderId, public string $patientId, public ?string $referrerId,
    ) {}

    public function name(): string
    {
        return 'diagnostics.ordered';
    }
}
