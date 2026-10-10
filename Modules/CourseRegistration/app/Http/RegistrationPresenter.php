<?php

namespace Modules\CourseRegistration\Http;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Modules\CourseRegistration\Models\Offering;
use Modules\CourseRegistration\Models\Registration;
use Modules\CourseRegistration\Models\RegistrationItem;
use Modules\CourseRegistration\Models\Window;
use Modules\Education\Directory\AcademicDirectory;

/**
 * What the API shows of offerings, windows and registrations, with the
 * subject (code, names, credits) beside each so screens need no second call.
 */
class RegistrationPresenter
{
    public function __construct(private AcademicDirectory $academic) {}

    /**
     * @param  array<string, array<string, mixed>>  $subjects  By id (AcademicDirectory::subjects).
     * @return array<string, mixed>
     */
    public function offering(Offering $offering, array $subjects, ?int $taken = null, ?int $waiting = null): array
    {
        return [
            'id' => $offering->getKey(),
            ...$offering->only(['unit_id', 'session_id', 'level_id', 'subject_id', 'group_name', 'kind', 'teacher_id', 'capacity', 'credits_centi', 'status', 'note', 'version']),
            'subject' => $subjects[$offering->subject_id] ?? null,
            'taken' => $taken,
            'waiting' => $waiting,
        ];
    }

    /** @return array<string, mixed>|null */
    public function window(?Window $window): ?array
    {
        return $window === null ? null : [
            'id' => $window->getKey(), 'session_id' => $window->session_id, 'opens_on' => $window->opens_on->toDateString(),
            'closes_on' => $window->closes_on->toDateString(), 'add_drop_until' => $window->add_drop_until->toDateString(), 'version' => $window->version,
        ];
    }

    /**
     * @param  Collection<int, RegistrationItem>|null  $items
     * @param  array<string, array<string, mixed>>  $subjects
     * @param  array<string, mixed>|null  $student
     * @return array<string, mixed>
     */
    public function registration(Registration $registration, ?Collection $items = null, array $subjects = [], ?array $student = null, array $offerings = []): array
    {
        return [
            'id' => $registration->getKey(),
            ...$registration->only(['unit_id', 'session_id', 'student_id', 'status', 'credits_centi', 'overload', 'submitted_by', 'approved_by', 'note', 'version']),
            'submitted_at' => $registration->submitted_at?->toIso8601String(),
            'approved_at' => $registration->approved_at?->toIso8601String(),
            'student' => $student === null ? null : array_intersect_key($student, array_flip(['id', 'code', 'name', 'name_local', 'program_id', 'status'])),
            ...($items === null ? [] : ['items' => $items->map(fn (RegistrationItem $item) => $this->item($item, $subjects, $offerings[$item->offering_id] ?? null))->values()]),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $subjects
     * @return array<string, mixed>
     */
    public function item(RegistrationItem $item, array $subjects = [], ?Offering $offering = null): array
    {
        return [
            'id' => $item->getKey(),
            ...$item->only(['registration_id', 'student_id', 'session_id', 'offering_id', 'subject_id', 'credits_centi', 'status', 'source', 'reason', 'outcome']),
            'subject' => $subjects[$item->subject_id] ?? null,
            'group_name' => $offering?->group_name,
            'waitlisted_at' => $item->waitlisted_at?->toIso8601String(),
            'registered_at' => $item->registered_at?->toIso8601String(),
            'ended_at' => $item->ended_at?->toIso8601String(),
        ];
    }

    /**
     * Subjects of offerings or items, by id.
     *
     * @param  iterable<Offering|RegistrationItem>  $rows
     * @return array<string, array<string, mixed>>
     */
    public function subjectsOf(Organization $company, iterable $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row->subject_id;
        }

        return $this->academic->subjects($company, array_values(array_unique($ids)));
    }
}
