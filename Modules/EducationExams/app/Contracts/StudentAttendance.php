<?php

namespace Modules\EducationExams\Contracts;

use App\Platform\Tenancy\Models\Organization;

/**
 * Days a student attended, for a marksheet. No module keeps students'
 * attendance yet; one that does binds this contract and marksheets show
 * the line without any change here. Unbound: no attendance line.
 */
interface StudentAttendance
{
    /**
     * Days present and school days of each student in a session (students without a record are left out).
     *
     * @param  list<string>  $studentIds
     * @return array<string, array{present: int, days: int}>
     */
    public function forSession(Organization $company, string $sessionId, array $studentIds): array;
}
