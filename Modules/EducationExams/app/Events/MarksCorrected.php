<?php

namespace Modules\EducationExams\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A published mark was corrected and the student's results worked out again. Ids only. */
class MarksCorrected
{
    use Dispatchable;

    public function __construct(public string $companyId, public string $examId, public string $studentId) {}
}
