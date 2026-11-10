<?php

namespace Modules\Diagnostics\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A sample was taken (manifest event "diagnostics.sample_collected"). Ids only.
 */
class SampleCollected
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $orderId, public string $sampleId,
    ) {}

    public function name(): string
    {
        return 'diagnostics.sample_collected';
    }
}
