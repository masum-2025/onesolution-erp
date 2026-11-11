<?php

namespace Modules\Inpatient\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tests were sent to the lab for an admitted patient (manifest event "inpatient.tests_ordered"). Ids only.
 */
class TestsOrdered
{
    use Dispatchable;

    public function __construct(public string $companyId, public string $admissionId, public string $patientId, public string $labOrderId) {}

    public function name(): string
    {
        return 'inpatient.tests_ordered';
    }
}
