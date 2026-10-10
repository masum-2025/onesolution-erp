<?php

namespace Modules\CourseRegistration\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\CourseRegistration\Exceptions\RegistrationException;
use Modules\CourseRegistration\Models\Offering;
use Modules\CourseRegistration\Models\RegistrationItem;
use Modules\CourseRegistration\Models\Window;
use Modules\Education\Directory\AcademicDirectory;
use Modules\Hrm\Directory\EmployeeDirectory;

/**
 * Subjects offered in a session and the session's registration window.
 *
 * - An offering is a subject at a campus in a group (A, B…), with seats,
 *   credits (the subject's by default) and a teacher from HRM (when HRM is
 *   on). Seats never go below the students holding one.
 * - A level's whole curriculum can be offered at once (one group each);
 *   what is already offered is left alone.
 * - The window says when students register, and until when they add and
 *   drop; after that a subject is withdrawn with a reason.
 */
class Offerings
{
    public function __construct(private Campus $campus, private AcademicDirectory $academic, private AuditLogger $audit) {}

    /**
     * Make ($offering null) or change an offering.
     *
     * @param  array<string, mixed>  $data  Validated by OfferingRequest.
     */
    public function save(Organization $company, ?Offering $offering, ?int $baseVersion, array $data, User $actor): Offering
    {
        return $this->campus->transaction($company, function () use ($company, $offering, $baseVersion, $data, $actor) {
            if ($offering !== null) {
                /** @var Offering $offering */
                $offering = $this->campus->query(Offering::class, $company)->whereKey($offering->getKey())->lockForUpdate()->firstOrFail();
                if ($offering->version !== $baseVersion) {
                    throw RegistrationException::versionConflict(['version' => $offering->version]);
                }
                $old = $this->values($offering);
                $offering->version++;
            } else {
                $offering = new Offering;
                $offering->fill(['organization_id' => $company->getKey(), 'version' => 1, 'status' => 'open']);
                $old = null;
            }
            $offering->fill(array_intersect_key($data, array_flip(['unit_id', 'session_id', 'level_id', 'subject_id', 'group_name', 'kind', 'teacher_id', 'capacity', 'credits_centi', 'status', 'note'])));
            $this->check($company, $offering);
            $offering->save();
            $this->audit->record($old === null ? 'course_registration.offering_created' : 'course_registration.offering_updated', $offering,
                old: $old ?? [], new: $this->values($offering), actor: $actor, organizationId: $company->getKey());

            return $offering;
        });
    }

    /**
     * Offer the curriculum of a level in a session at a campus: one group of each subject not offered there yet.
     *
     * @return list<Offering> What was made.
     */
    public function fromCurriculum(Organization $company, string $unitId, string $sessionId, string $levelId, string $group, int $capacity, User $actor): array
    {
        $items = $this->academic->curriculum($company, $levelId, $sessionId);
        $taken = $this->campus->query(Offering::class, $company)->where('session_id', $sessionId)->where('unit_id', $unitId)->pluck('subject_id')->all();
        $made = [];
        foreach ($items as $item) {
            if (in_array($item['subject_id'], $taken, true)) {
                continue;
            }
            $made[] = $this->save($company, null, null, [
                'unit_id' => $unitId, 'session_id' => $sessionId, 'level_id' => $levelId, 'subject_id' => $item['subject_id'],
                'group_name' => $group, 'kind' => $item['kind'], 'capacity' => $capacity,
            ], $actor);
            $taken[] = $item['subject_id'];
        }

        return $made;
    }

    /**
     * The registration window of a session (made or changed).
     *
     * @param  array{opens_on: string, closes_on: string, add_drop_until: string, base_version?: int}  $data
     */
    public function saveWindow(Organization $company, string $sessionId, array $data, User $actor): Window
    {
        $session = $this->academic->session($company, $sessionId) ?? throw RegistrationException::notFound('session');
        $errors = [];
        if ($data['closes_on'] < $data['opens_on']) {
            $errors['closes_on'] = __('course_registration::registration.validation.window_order');
        }
        if ($data['add_drop_until'] < $data['opens_on'] || $data['add_drop_until'] > $session['ends_on']) {
            $errors['add_drop_until'] = __('course_registration::registration.validation.add_drop_inside', ['ends' => $session['ends_on']]);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->campus->transaction($company, function () use ($company, $sessionId, $data, $actor) {
            $window = $this->campus->query(Window::class, $company)->where('session_id', $sessionId)->lockForUpdate()->first();
            if ($window !== null && isset($data['base_version']) && $window->version !== (int) $data['base_version']) {
                throw RegistrationException::versionConflict(['version' => $window->version]);
            }
            $old = $window?->only(['opens_on', 'closes_on', 'add_drop_until']);
            $window ??= (new Window)->fill(['organization_id' => $company->getKey(), 'session_id' => $sessionId, 'created_by' => $actor->getKey(), 'version' => 0]);
            $window->fill(array_intersect_key($data, array_flip(['opens_on', 'closes_on', 'add_drop_until'])));
            $window->version++;
            $window->save();
            $this->audit->record('course_registration.window_saved', $window, old: $old === null ? [] : array_map(fn ($date) => $date->toDateString(), $old),
                new: ['session_id' => $sessionId, ...array_intersect_key($data, array_flip(['opens_on', 'closes_on', 'add_drop_until']))], actor: $actor, organizationId: $company->getKey());

            return $window;
        });
    }

    /** Students holding a seat in an offering. */
    public function taken(Organization $company, string $offeringId): int
    {
        return $this->campus->query(RegistrationItem::class, $company)->where('offering_id', $offeringId)->where('status', 'registered')->count();
    }

    /** What must fit before an offering is kept. */
    private function check(Organization $company, Offering $offering): void
    {
        $errors = [];
        if (! in_array($offering->unit_id, $this->campus->subtreeIds($company), true)) {
            $errors['unit_id'] = __('course_registration::registration.validation.reference');
        }
        $session = $this->academic->session($company, $offering->session_id);
        if ($session === null) {
            $errors['session_id'] = __('course_registration::registration.validation.reference');
        }
        $subject = $this->academic->subjects($company, [$offering->subject_id])[$offering->subject_id] ?? null;
        if ($subject === null) {
            $errors['subject_id'] = __('course_registration::registration.validation.reference');
        }
        if ($offering->level_id !== null && $this->academic->level($company, $offering->level_id) === null) {
            $errors['level_id'] = __('course_registration::registration.validation.reference');
        }
        if ($offering->teacher_id !== null && $offering->isDirty('teacher_id') && ! $this->teacherExists($company, $offering->teacher_id)) {
            $errors['teacher_id'] = __('course_registration::registration.validation.reference');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if (! $offering->exists && ! isset($offering->getAttributes()['credits_centi'])) {
            $offering->credits_centi = $subject['credits_centi'];
        }
        $duplicate = $this->campus->query(Offering::class, $company)->where('session_id', $offering->session_id)->where('unit_id', $offering->unit_id)
            ->where('subject_id', $offering->subject_id)->where('group_name', $offering->group_name)
            ->when($offering->exists, fn ($query) => $query->whereKeyNot($offering->getKey()))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['group_name' => __('course_registration::registration.validation.group_taken')]);
        }
        // Cancelled only once nobody holds a seat or waits (move them first).
        if ($offering->exists && $offering->isDirty('status') && $offering->status === 'cancelled'
            && $this->campus->query(RegistrationItem::class, $company)->where('offering_id', $offering->getKey())->whereIn('status', RegistrationItem::ACTIVE)->exists()) {
            throw ValidationException::withMessages(['status' => __('course_registration::registration.validation.cancel_in_use')]);
        }
        if ($offering->exists && $offering->isDirty('capacity') && $offering->capacity < ($taken = $this->taken($company, $offering->getKey()))) {
            throw ValidationException::withMessages(['capacity' => __('course_registration::registration.validation.capacity_below', ['count' => $taken])]);
        }
    }

    private function teacherExists(Organization $company, string $employeeId): bool
    {
        return class_exists(EmployeeDirectory::class) && app(ModuleResolver::class)->isEnabled('hrm', $company)
            && app(EmployeeDirectory::class)->find($company, $employeeId) !== null;
    }

    /** @return array<string, mixed> */
    private function values(Offering $offering): array
    {
        return $offering->only(['unit_id', 'session_id', 'level_id', 'subject_id', 'group_name', 'kind', 'teacher_id', 'capacity', 'credits_centi', 'status']);
    }
}
