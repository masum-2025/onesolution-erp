<?php

namespace Modules\Education\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A student joined, moved within or ended an enrollment (section, roll,
 * status). Other modules (fees, attendance, exams) read what they need
 * through Education's public services; the payload carries ids only.
 */
class EnrollmentChanged
{
    use Dispatchable;

    public function __construct(public string $companyId, public string $enrollmentId, public string $studentId) {}
}
