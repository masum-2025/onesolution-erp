<?php

namespace Modules\Inpatient\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An admitted patient moved to another bed (manifest event "inpatient.bed_moved"). Ids only.
 */
class BedMoved
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $admissionId, public string $patientId, public string $bedId,
    ) {}

    public function name(): string
    {
        return 'inpatient.bed_moved';
    }
}
