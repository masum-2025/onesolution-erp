<?php

namespace Modules\Outpatient\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A doctor finished seeing a patient (manifest event "outpatient.consultation_done"). Ids only, never what was written.
 */
class ConsultationDone
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $consultationId, public string $patientId, public string $doctorId,
    ) {}

    public function name(): string
    {
        return 'outpatient.consultation_done';
    }
}
