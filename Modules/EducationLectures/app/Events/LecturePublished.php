<?php

namespace Modules\EducationLectures\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A lecture became visible to its section's students and guardians. Ids only.
 */
class LecturePublished
{
    use Dispatchable;

    public function __construct(public string $companyId, public string $lectureId) {}
}
