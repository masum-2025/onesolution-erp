<?php

namespace Modules\EducationExams\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Results were published: an exam's, or a combination's (several exams
 * weighed). Course registration records outcomes from them; families are
 * told (EXM-4). Ids only.
 */
class ResultPublished
{
    use Dispatchable;

    public function __construct(public string $companyId, public string $sourceType, public string $sourceId, public string $sessionId) {}
}
