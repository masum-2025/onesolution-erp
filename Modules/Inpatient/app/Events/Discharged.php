<?php

namespace Modules\Inpatient\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A patient was discharged with a signed summary (manifest event "inpatient.discharged"). Ids only.
 */
class Discharged
{
    use Dispatchable;

    public function __construct(public string $companyId, public string $admissionId, public string $patientId, public string $dischargeId) {}

    public function name(): string
    {
        return 'inpatient.discharged';
    }
}
