<?php

namespace Modules\CourseRegistration\Directory;

use App\Platform\Tenancy\Models\Organization;
use Modules\CourseRegistration\Models\Registration;
use Modules\CourseRegistration\Services\Campus;

/**
 * Course registration's public service for other modules (fees, exams):
 * a registration's student, session, campus, status and credits, read
 * only, as a plain array. Every lookup names the institution.
 */
class RegistrationDirectory
{
    public function __construct(private Campus $campus) {}

    /** @return array{id: string, student_id: string, session_id: string, unit_id: string, status: string, credits_centi: int}|null */
    public function registration(Organization $company, string $id): ?array
    {
        $registration = $this->campus->query(Registration::class, $company)->whereKey($id)->first();

        return $registration === null ? null : [
            'id' => $registration->getKey(), 'student_id' => $registration->student_id, 'session_id' => $registration->session_id,
            'unit_id' => $registration->unit_id, 'status' => $registration->status, 'credits_centi' => (int) $registration->credits_centi,
        ];
    }
}
