<?php

namespace Modules\Patients\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A duplicate patient was folded into another (manifest event
 * "patients.merged"): modules holding the source id move their records to
 * the target. Ids only.
 */
class PatientsMerged
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $sourceId,
        public string $targetId,
    ) {}

    public function name(): string
    {
        return 'patients.merged';
    }
}
