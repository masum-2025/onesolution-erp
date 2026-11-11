<?php

namespace Modules\Inpatient\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A patient was admitted to a bed (manifest event "inpatient.admitted"). Ids only.
 */
class Admitted
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $admissionId, public string $patientId, public string $bedId,
    ) {}

    public function name(): string
    {
        return 'inpatient.admitted';
    }
}
