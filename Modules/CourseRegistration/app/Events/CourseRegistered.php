<?php

namespace Modules\CourseRegistration\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A student holds a seat in an offering (registered directly, or moved up from the waiting list). Ids only.
 */
class CourseRegistered
{
    use Dispatchable;

    public function __construct(public string $companyId, public string $itemId, public string $studentId, public string $offeringId) {}
}
