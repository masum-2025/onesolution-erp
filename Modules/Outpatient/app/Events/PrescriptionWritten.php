<?php

namespace Modules\Outpatient\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A finished consultation has medicines (the pharmacy may get ready) (manifest event "outpatient.prescription_written"). Ids only, never what was written.
 */
class PrescriptionWritten
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $consultationId, public string $patientId, public string $doctorId,
    ) {}

    public function name(): string
    {
        return 'outpatient.prescription_written';
    }
}
