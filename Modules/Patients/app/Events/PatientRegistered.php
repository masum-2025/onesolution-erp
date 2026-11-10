<?php

namespace Modules\Patients\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new patient (manifest event "patients.registered"). Ids only.
 */
class PatientRegistered
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $patientId,
        public string $unitId,
    ) {}

    public function name(): string
    {
        return 'patients.registered';
    }
}
