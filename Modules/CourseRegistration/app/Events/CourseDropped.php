<?php

namespace Modules\CourseRegistration\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A student left an offering (status says dropped or withdrawn). Ids only.
 */
class CourseDropped
{
    use Dispatchable;

    public function __construct(public string $companyId, public string $itemId, public string $studentId, public string $offeringId, public string $status) {}
}
