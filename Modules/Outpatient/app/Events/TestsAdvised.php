<?php

namespace Modules\Outpatient\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A finished consultation advised tests (the lab may get ready) (manifest event "outpatient.tests_advised"). Ids only, never what was written.
 */
class TestsAdvised
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $consultationId, public string $patientId, public string $doctorId,
    ) {}

    public function name(): string
    {
        return 'outpatient.tests_advised';
    }
}
