<?php

namespace Modules\Education\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Education\Events\EnrollmentChanged;
use Modules\Education\Exceptions\EducationException;
use Modules\Education\Models\Enrollment;
use Modules\Education\Models\Level;
use Modules\Education\Models\Program;
use Modules\Education\Models\Section;
use Modules\Education\Models\Session;
use Modules\Education\Models\Student;

/**
 * A student in a session, a level and (once placed) a section, with a roll.
 *
 * One enrollment per student and session; it is never rewritten for a new
 * period (promotion makes the next one), so the history stays. A section
 * takes no more than its capacity. Rolls follow the rule
 * education.roll_number_mode: given by hand, by name, or in admission order.
 */
class Enrollments
{
    public function __construct(
        private Education $education,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /** Put a student into a session and level (and a section). Call inside a transaction. */
    public function enroll(Organization $company, Student $student, string $sessionId, string $levelId, ?string $sectionId, CarbonImmutable $startedOn): Enrollment
    {
        /** @var Session $session */
        $session = $this->education->find(Session::class, $company, $sessionId, 'session');
        /** @var Level $level */
        $level = $this->education->find(Level::class, $company, $levelId, 'level');
        /** @var Program $program */
        $program = $this->education->find(Program::class, $company, $level->program_id, 'program');

        if ($level->program_id !== $student->program_id) {
            throw EducationException::mismatch('level_id');
        }
        if ($session->kind !== $program->progression || $session->status === 'closed') {
            throw EducationException::mismatch('session_id');
        }
        if ($this->education->query(Enrollment::class, $company)->where('student_id', $student->getKey())->where('session_id', $sessionId)->exists()) {
            throw EducationException::alreadyEnrolled();
        }

        $enrollment = new Enrollment;
        $enrollment->fill([
            'organization_id' => $company->getKey(),
            'unit_id' => $student->unit_id,
            'student_id' => $student->getKey(),
            'session_id' => $sessionId,
            'level_id' => $levelId,
            'status' => 'active',
            'started_on' => $startedOn->toDateString(),
            'version' => 1,
        ]);
        if ($sectionId !== null) {
            $this->intoSection($company, $enrollment, $sectionId);
        }
        $enrollment->save();
        $this->changed($company, $enrollment);

        return $enrollment;
    }

    /** Move an active enrollment to another section of the same session and level (or out of any). */
    public function place(Organization $company, Enrollment $enrollment, ?string $sectionId, int $baseVersion, User $actor): Enrollment
    {
        return $this->education->transaction($company, function () use ($company, $enrollment, $sectionId, $baseVersion, $actor) {
            $enrollment = $this->locked($company, $enrollment, $baseVersion);
            if ($enrollment->status !== 'active') {
                throw EducationException::wrongStatus($enrollment->status);
            }
            $old = $enrollment->only(['section_id', 'roll_no']);
            if ($sectionId === null) {
                $enrollment->forceFill(['section_id' => null, 'roll_no' => null]);
            } elseif ($sectionId !== $enrollment->section_id) {
                $this->intoSection($company, $enrollment, $sectionId);
            }
            $enrollment->version++;
            $enrollment->save();
            $this->audit->record('education.enrollment_placed', $enrollment, old: $old, new: $enrollment->only(['section_id', 'roll_no']), actor: $actor, organizationId: $company->getKey());
            $this->changed($company, $enrollment);

            return $enrollment;
        });
    }

    /**
     * Rolls of a section's active students: given by hand ($rolls: enrollment
     * id => roll), or worked out by the rule's mode ($rolls null).
     *
     * @param  array<string, int>|null  $rolls
     */
    public function renumber(Organization $company, Section $section, ?array $rolls, User $actor): int
    {
        return $this->education->transaction($company, function () use ($company, $section, $rolls, $actor) {
            $enrollments = $this->education->query(Enrollment::class, $company)->where('section_id', $section->getKey())->where('status', 'active')->lockForUpdate()->get()->keyBy('id');

            if ($rolls !== null) {
                if (array_diff_key($rolls, $enrollments->all()) !== []) {
                    throw EducationException::notFound('enrollment');
                }
                if (count(array_unique($rolls)) !== count($rolls)) {
                    throw ValidationException::withMessages(['rolls' => __('education::education.validation.roll_twice')]);
                }
                // Rolls not given keep theirs, unless one would be taken twice.
                $kept = $enrollments->except(array_keys($rolls))->pluck('roll_no')->filter()->all();
                if (array_intersect($kept, $rolls) !== []) {
                    throw ValidationException::withMessages(['rolls' => __('education::education.validation.roll_twice')]);
                }
                $order = $rolls;
            } else {
                $mode = (string) $this->rules->get('education.roll_number_mode', $this->contexts->forOrganization($company));
                $students = $this->education->query(Student::class, $company)->whereKey($enrollments->pluck('student_id')->all())->get()->keyBy('id');
                $sorted = $enrollments->sortBy(fn (Enrollment $enrollment) => $mode === 'name'
                    ? mb_strtolower((string) $students[$enrollment->student_id]?->name).'|'.$students[$enrollment->student_id]?->code
                    : $students[$enrollment->student_id]?->admitted_on?->toDateString().'|'.$students[$enrollment->student_id]?->code);
                $order = [];
                $next = 1;
                foreach ($sorted as $enrollment) {
                    $order[$enrollment->getKey()] = $next++;
                }
            }

            foreach ($order as $id => $roll) {
                $enrollments[$id]->forceFill(['roll_no' => (int) $roll, 'version' => $enrollments[$id]->version + 1])->save();
            }
            $this->audit->record('education.rolls_numbered', $section, new: ['count' => count($order), 'by_hand' => $rolls !== null], actor: $actor, organizationId: $company->getKey());

            return count($order);
        });
    }

    /** The student's current (active) enrollment, if any. */
    public function current(Organization $company, string $studentId): ?Enrollment
    {
        return $this->education->query(Enrollment::class, $company)->where('student_id', $studentId)->where('status', 'active')->orderByDesc('started_on')->first();
    }

    /** End the active enrollment (the student left, moved or graduated). Call inside a transaction. */
    public function end(Organization $company, string $studentId, string $status, CarbonImmutable $on): void
    {
        $enrollment = $this->education->query(Enrollment::class, $company)->where('student_id', $studentId)->where('status', 'active')->lockForUpdate()->first();
        if ($enrollment === null) {
            return;
        }
        $enrollment->forceFill(['status' => $status, 'ended_on' => $on->toDateString(), 'version' => $enrollment->version + 1])->save();
        $this->changed($company, $enrollment);
    }

    private function intoSection(Organization $company, Enrollment $enrollment, string $sectionId): void
    {
        /** @var Section $section */
        $section = $this->education->query(Section::class, $company)->whereKey($sectionId)->lockForUpdate()->first() ?? throw EducationException::notFound('section');
        if ($section->session_id !== $enrollment->session_id || $section->level_id !== $enrollment->level_id || ! $section->is_active) {
            throw EducationException::mismatch('section_id');
        }
        if ($section->unit_id !== $enrollment->unit_id) {
            throw EducationException::mismatch('unit_id');
        }
        $taken = $this->education->query(Enrollment::class, $company)->where('section_id', $sectionId)->where('status', 'active')
            ->when($enrollment->exists, fn ($query) => $query->whereKeyNot($enrollment->getKey()))->count();
        if ($taken >= $section->capacity) {
            throw EducationException::sectionFull($section->capacity);
        }

        $roll = null;
        if ((string) $this->rules->get('education.roll_number_mode', $this->contexts->forOrganization($company)) !== 'manual') {
            $roll = (int) $this->education->query(Enrollment::class, $company)->where('section_id', $sectionId)->where('status', 'active')->max('roll_no') + 1;
        }
        $enrollment->forceFill(['section_id' => $sectionId, 'roll_no' => $roll]);
    }

    private function locked(Organization $company, Enrollment $enrollment, int $baseVersion): Enrollment
    {
        /** @var Enrollment $fresh */
        $fresh = $this->education->query(Enrollment::class, $company)->whereKey($enrollment->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw EducationException::versionConflict(['version' => $fresh->version]);
        }

        return $fresh;
    }

    private function changed(Organization $company, Enrollment $enrollment): void
    {
        $event = new EnrollmentChanged($company->getKey(), $enrollment->getKey(), $enrollment->student_id);
        DB::afterCommit(fn () => event($event));
    }
}
