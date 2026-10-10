<?php

namespace Modules\CourseRegistration\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A student moved up from the waiting list into a freed seat. Ids only.
 */
class SeatOffered
{
    use Dispatchable;

    public function __construct(public string $companyId, public string $itemId, public string $studentId, public string $offeringId) {}
}
