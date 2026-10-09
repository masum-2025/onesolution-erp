<?php

namespace Modules\Education\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new student (directly, or from an admission). Ids only.
 */
class StudentAdmitted
{
    use Dispatchable;

    public function __construct(public string $companyId, public string $studentId, public ?string $admissionId = null) {}
}
