<?php

namespace Modules\Outpatient\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A patient got a serial for a doctor (manifest event "outpatient.serial_booked"). Ids only.
 */
class SerialBooked
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $serialId, public string $patientId, public string $doctorId,
    ) {}

    public function name(): string
    {
        return 'outpatient.serial_booked';
    }
}
