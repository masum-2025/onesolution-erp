<?php

namespace Modules\CourseRegistration\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A student's registration for a session was approved (or needed no approval). Ids only.
 */
class RegistrationApproved
{
    use Dispatchable;

    public function __construct(public string $companyId, public string $registrationId, public string $studentId) {}
}
