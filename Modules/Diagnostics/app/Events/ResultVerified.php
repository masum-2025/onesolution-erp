<?php

namespace Modules\Diagnostics\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A test's results were verified (or entered where verification is off) (manifest event "diagnostics.result_verified"). Ids only.
 */
class ResultVerified
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $orderId, public string $orderTestId, public int $amendment,
    ) {}

    public function name(): string
    {
        return 'diagnostics.result_verified';
    }
}
