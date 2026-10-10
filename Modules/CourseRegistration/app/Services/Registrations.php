<?php

namespace Modules\CourseRegistration\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\CourseRegistration\Events\CourseDropped;
use Modules\CourseRegistration\Events\CourseRegistered;
use Modules\CourseRegistration\Events\RegistrationApproved;
use Modules\CourseRegistration\Events\SeatOffered;
use Modules\CourseRegistration\Exceptions\RegistrationException;
use Modules\CourseRegistration\Models\Offering;
use Modules\CourseRegistration\Models\Registration;
use Modules\CourseRegistration\Models\RegistrationItem;
use Modules\CourseRegistration\Models\Window;
use Modules\Education\Directory\AcademicDirectory;

/**
 * Students registering for the subjects offered in a session.
 *
 * - One registration per student and session, at the campus they study
 *   at: draft -> submitted -> approved, or returned with a note. When the
 *   rule course_registration.approval_required is off, handing in approves
 *   (unless the student takes more credits than the maximum: an overload
 *   always needs approval). A change after approval asks for it again.
 * - A subject is added when it is offered in the student's session at their
 *   campus (or at the institution), open, not already taken, its
 *   prerequisites completed (rule prerequisites_enforced) and the credits
 *   within max_credits + overload_credits. A full offering puts the student
 *   on its waiting list (rule waitlist), else it is refused.
 * - Students act only while the session's window is open (and only with
 *   self_registration); staff act any time. Until the add/drop date a
 *   subject is dropped; after it, withdrawn with a reason. Nothing is
 *   deleted. A freed seat goes to the first on the waiting list who fits.
 * - Outcomes (completed, failed, incomplete) are what prerequisites check.
 * - Every change is audited; events carry ids only.
 */
class Registrations
{
    public function __construct(
        private Campus $campus,
        private AcademicDirectory $academic,
        private Offerings $offerings,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /** The student's registration for a session (made as a draft the first time). */
    public function for(Organization $company, string $studentId, string $sessionId, User $actor): Registration
    {
        $existing = $this->campus->query(Registration::class, $company)->where('student_id', $studentId)->where('session_id', $sessionId)->first();
        if ($existing !== null) {
            return $existing;
        }
        $student = $this->academic->student($company, $studentId) ?? throw RegistrationException::notFound('student');
        $enrollment = $this->academic->enrollment($company, $studentId, $sessionId);
        if ($enrollment === null || ! in_array($student['status'], ['active', 'suspended'], true)) {
            throw RegistrationException::notStudying($student['name']);
        }

        return $this->campus->transaction($company, function () use ($company, $student, $enrollment, $sessionId, $actor) {
            $registration = new Registration;
            $registration->fill([
                'organization_id' => $company->getKey(), 'unit_id' => $enrollment['unit_id'], 'session_id' => $sessionId,
                'student_id' => $student['id'], 'status' => 'draft', 'credits_centi' => 0, 'overload' => false, 'version' => 1,
            ]);
            $registration->save();
            $this->audit->record('course_registration.registration_opened', $registration, new: ['student_id' => $student['id'], 'session_id' => $sessionId], actor: $actor, organizationId: $company->getKey());

            return $registration;
        });
    }

    /**
     * Add a subject (an offering) to a registration.
     *
     * @param  bool  $byStudent  The student themselves (portal): only in the window.
     */
    public function add(Organization $company, Registration $registration, string $offeringId, User $actor, bool $byStudent = false, string $source = 'staff', ?string $opId = null): RegistrationItem
    {
        if ($opId !== null && ($done = $this->campus->query(RegistrationItem::class, $company)->where('op_id', $opId)->first()) !== null) {
            return $done;
        }
        if ($byStudent) {
            $this->assertWindowOpen($company, $registration->session_id);
        }

        $item = $this->campus->transaction($company, function () use ($company, $registration, $offeringId, $actor, $source, $opId) {
            $registration = $this->locked($company, $registration);
            /** @var Offering $offering */
            $offering = $this->campus->query(Offering::class, $company)->whereKey($offeringId)->lockForUpdate()->first() ?? throw RegistrationException::notFound('offering');
            if ($offering->status !== 'open') {
                throw RegistrationException::offeringNotOpen();
            }
            if ($offering->session_id !== $registration->session_id || ! in_array($offering->unit_id, [$registration->unit_id, $company->getKey()], true)) {
                throw RegistrationException::offeringElsewhere();
            }
            $subject = $this->academic->subjects($company, [$offering->subject_id])[$offering->subject_id] ?? ['code' => '?'];
            $active = $this->items($company, $registration)->whereIn('status', RegistrationItem::ACTIVE);
            if ($active->contains('subject_id', $offering->subject_id)) {
                throw RegistrationException::alreadyRegistered($subject['code']);
            }
            $this->assertPrerequisites($company, $registration->student_id, $offering->subject_id, $subject['code']);

            $hasSeat = $this->offerings->taken($company, $offering->getKey()) < $offering->capacity;
            if (! $hasSeat && ! $this->rule($company, 'waitlist')) {
                throw RegistrationException::offeringFull($offering->capacity);
            }
            if ($hasSeat) {
                $this->assertCredits($company, $registration, $offering->credits_centi);
            }

            $item = new RegistrationItem;
            $item->fill([
                'organization_id' => $company->getKey(), 'registration_id' => $registration->getKey(), 'student_id' => $registration->student_id,
                'session_id' => $registration->session_id, 'offering_id' => $offering->getKey(), 'subject_id' => $offering->subject_id,
                'credits_centi' => $offering->credits_centi, 'status' => $hasSeat ? 'registered' : 'waitlisted', 'source' => $source,
                'registered_at' => $hasSeat ? now() : null, 'waitlisted_at' => $hasSeat ? null : now(),
                'created_by' => $actor->getKey(), 'op_id' => $opId,
            ]);
            $item->save();
            $this->changed($company, $registration);
            $this->audit->record('course_registration.course_added', $item, new: $item->only(['student_id', 'offering_id', 'subject_id', 'status', 'source']), actor: $actor, organizationId: $company->getKey());
            if ($hasSeat) {
                DB::afterCommit(fn () => event(new CourseRegistered($company->getKey(), $item->getKey(), $item->student_id, $item->offering_id)));
            }

            return $item;
        });

        return $item;
    }

    /**
     * Take a subject off: dropped until the add/drop date, withdrawn after it
     * (a reason needed; the outcome says withdrawn). A freed seat goes to
     * the waiting list.
     */
    public function drop(Organization $company, RegistrationItem $item, ?string $reason, User $actor, bool $byStudent = false): RegistrationItem
    {
        if ($byStudent) {
            $this->assertWindowOpen($company, $item->session_id, forDrop: true);
        }

        return $this->campus->transaction($company, function () use ($company, $item, $reason, $actor) {
            /** @var RegistrationItem $item */
            $item = $this->campus->query(RegistrationItem::class, $company)->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($item->status, RegistrationItem::ACTIVE, true)) {
                throw RegistrationException::wrongStatus($item->status);
            }
            $registration = $this->locked($company, $this->campus->find(Registration::class, $company, $item->registration_id, 'registration'));
            $window = $this->window($company, $item->session_id);
            $late = $item->status === 'registered' && $window !== null && $this->campus->today($company)->toDateString() > $window->add_drop_until->toDateString();
            $reason = trim((string) $reason) ?: null;
            if ($late && $reason === null) {
                throw RegistrationException::reasonNeeded();
            }
            $held = $item->status === 'registered';
            $item->fill(['status' => $late ? 'withdrawn' : 'dropped', 'ended_at' => now(), 'reason' => $reason, 'outcome' => $late ? 'withdrawn' : null])->save();
            $this->changed($company, $registration);
            $this->audit->record('course_registration.course_'.($late ? 'withdrawn' : 'dropped'), $item, new: $item->only(['student_id', 'offering_id', 'status', 'reason']), actor: $actor, organizationId: $company->getKey());
            DB::afterCommit(fn () => event(new CourseDropped($company->getKey(), $item->getKey(), $item->student_id, $item->offering_id, $item->status)));
            if ($held) {
                $this->promote($company, $item->offering_id, $actor);
            }

            return $item;
        });
    }

    /** Hand in: the minimum credits; approved at once unless approval is needed (always for an overload). */
    public function submit(Organization $company, Registration $registration, User $actor, bool $byStudent = false): Registration
    {
        if ($byStudent) {
            $this->assertWindowOpen($company, $registration->session_id);
        }

        return $this->campus->transaction($company, function () use ($company, $registration, $actor) {
            $registration = $this->locked($company, $registration);
            if (! in_array($registration->status, ['draft', 'returned'], true)) {
                throw RegistrationException::wrongStatus($registration->status);
            }
            $minimum = $this->credits($company, 'min_credits');
            if ($minimum > 0 && $registration->credits_centi < $minimum) {
                throw RegistrationException::creditsUnder($this->creditText($minimum));
            }
            $registration->fill(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $actor->getKey()]);
            if (! $this->rule($company, 'approval_required') && ! $registration->overload) {
                $this->markApproved($company, $registration, null);
            }
            $registration->version++;
            $registration->save();
            $this->audit->record('course_registration.registration_submitted', $registration, new: $registration->only(['status', 'credits_centi', 'overload']), actor: $actor, organizationId: $company->getKey());

            return $registration;
        });
    }

    /** Approve a handed-in registration: never by the student, nor by whoever handed it in. */
    public function approve(Organization $company, Registration $registration, int $baseVersion, User $actor): Registration
    {
        return $this->campus->transaction($company, function () use ($company, $registration, $baseVersion, $actor) {
            $registration = $this->locked($company, $registration, $baseVersion);
            if ($registration->status !== 'submitted') {
                throw RegistrationException::wrongStatus($registration->status);
            }
            $student = $this->academic->student($company, $registration->student_id);
            if ($actor->getKey() === $registration->submitted_by || ($student !== null && $actor->getKey() === $student['user_id'])) {
                throw RegistrationException::ownApproval();
            }
            $this->markApproved($company, $registration, $actor);
            $registration->version++;
            $registration->save();
            $this->audit->record('course_registration.registration_approved', $registration, new: $registration->only(['credits_centi', 'overload']), actor: $actor, organizationId: $company->getKey());

            return $registration;
        });
    }

    /** Send back to be changed, with a note. */
    public function sendBack(Organization $company, Registration $registration, int $baseVersion, string $note, User $actor): Registration
    {
        return $this->campus->transaction($company, function () use ($company, $registration, $baseVersion, $note, $actor) {
            $registration = $this->locked($company, $registration, $baseVersion);
            if (! in_array($registration->status, ['submitted', 'approved'], true)) {
                throw RegistrationException::wrongStatus($registration->status);
            }
            $registration->fill(['status' => 'returned', 'note' => $note, 'approved_at' => null, 'approved_by' => null]);
            $registration->version++;
            $registration->save();
            $this->audit->record('course_registration.registration_returned', $registration, new: ['note' => $note], actor: $actor, organizationId: $company->getKey());

            return $registration;
        });
    }

    /**
     * Register every student of a section for the compulsory subjects offered
     * to their class (at their campus or the institution), then hand each
     * registration in. One student's problem never stops the others.
     *
     * @return array{students: int, added: int, waitlisted: int, problems: list<array{student_id: string, name: string, message: string}>}
     */
    public function registerSection(Organization $company, string $sectionId, User $actor): array
    {
        $section = $this->academic->section($company, $sectionId) ?? throw RegistrationException::notFound('section');
        $offerings = $this->campus->query(Offering::class, $company)->where('session_id', $section['session_id'])->where('level_id', $section['level_id'])
            ->where('kind', 'compulsory')->where('status', 'open')->whereIn('unit_id', [$section['unit_id'], $company->getKey()])
            ->orderBy('group_name')->get()->unique('subject_id');
        $result = ['students' => 0, 'added' => 0, 'waitlisted' => 0, 'problems' => []];

        foreach ($this->academic->sectionStudents($company, $sectionId) as $student) {
            $result['students']++;
            try {
                $registration = $this->for($company, $student['id'], $section['session_id'], $actor);
                $taken = $this->items($company, $registration)->whereIn('status', RegistrationItem::ACTIVE)->pluck('subject_id')->all();
                foreach ($offerings as $offering) {
                    if (in_array($offering->subject_id, $taken, true)) {
                        continue;
                    }
                    $item = $this->add($company, $registration->refresh(), $offering->getKey(), $actor, source: 'section');
                    $result[$item->status === 'registered' ? 'added' : 'waitlisted']++;
                }
                $registration->refresh();
                if (in_array($registration->status, ['draft', 'returned'], true) && $registration->credits_centi > 0) {
                    $this->submit($company, $registration, $actor);
                }
            } catch (RegistrationException $problem) {
                $result['problems'][] = ['student_id' => $student['id'], 'name' => $student['name'], 'message' => $problem->getMessage()];
            }
        }
        $this->audit->record('course_registration.section_registered', null, new: ['section_id' => $sectionId, 'added' => $result['added'], 'waitlisted' => $result['waitlisted'], 'problems' => count($result['problems'])],
            actor: $actor, organizationId: $company->getKey());

        return $result;
    }

    /** The result of a subject: completed, failed, incomplete (or withdrawn). */
    public function recordOutcome(Organization $company, RegistrationItem $item, string $outcome, User $actor): RegistrationItem
    {
        return $this->campus->transaction($company, function () use ($company, $item, $outcome, $actor) {
            /** @var RegistrationItem $item */
            $item = $this->campus->query(RegistrationItem::class, $company)->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($item->status, ['registered', 'withdrawn'], true)) {
                throw RegistrationException::wrongStatus($item->status);
            }
            $old = $item->outcome;
            $item->fill(['outcome' => $outcome, 'outcome_by' => $actor->getKey(), 'outcome_at' => now()])->save();
            $this->audit->record('course_registration.outcome_recorded', $item, old: ['outcome' => $old], new: ['outcome' => $outcome, 'student_id' => $item->student_id, 'subject_id' => $item->subject_id],
                actor: $actor, organizationId: $company->getKey());

            return $item;
        });
    }

    /** The window of a session, if one was set. */
    public function window(Organization $company, string $sessionId): ?Window
    {
        return $this->campus->query(Window::class, $company)->where('session_id', $sessionId)->first();
    }

    /**
     * Items of a registration, oldest first.
     *
     * @return \Illuminate\Support\Collection<int, RegistrationItem>
     */
    public function items(Organization $company, Registration $registration): \Illuminate\Support\Collection
    {
        return $this->campus->query(RegistrationItem::class, $company)->where('registration_id', $registration->getKey())->orderBy('created_at')->orderBy('id')->get();
    }

    /** The limits the screens explain, in hundredths of a credit (0: none). */
    public function limits(Organization $company): array
    {
        return [
            'min_credits_centi' => $this->credits($company, 'min_credits'),
            'max_credits_centi' => $this->credits($company, 'max_credits'),
            'overload_credits_centi' => $this->credits($company, 'overload_credits'),
            'approval_required' => $this->rule($company, 'approval_required'),
            'prerequisites_enforced' => $this->rule($company, 'prerequisites_enforced'),
            'waitlist' => $this->rule($company, 'waitlist'),
            'self_registration' => $this->rule($company, 'self_registration'),
        ];
    }

    /** The first on an offering's waiting list who still fits gets the freed seat (call inside a transaction). */
    private function promote(Organization $company, string $offeringId, User $actor): void
    {
        /** @var Offering $offering */
        $offering = $this->campus->query(Offering::class, $company)->whereKey($offeringId)->lockForUpdate()->first();
        if ($offering === null || $offering->status !== 'open') {
            return;
        }
        $waiting = $this->campus->query(RegistrationItem::class, $company)->where('offering_id', $offeringId)->where('status', 'waitlisted')
            ->orderBy('waitlisted_at')->orderBy('id')->lockForUpdate()->get();
        foreach ($waiting as $item) {
            if ($this->offerings->taken($company, $offeringId) >= $offering->capacity) {
                return;
            }
            $registration = $this->locked($company, $this->campus->find(Registration::class, $company, $item->registration_id, 'registration'));
            try {
                $this->assertCredits($company, $registration, $item->credits_centi);
            } catch (RegistrationException) {
                continue;
            }
            $item->fill(['status' => 'registered', 'registered_at' => now()])->save();
            $this->changed($company, $registration);
            $this->audit->record('course_registration.seat_offered', $item, new: $item->only(['student_id', 'offering_id']), actor: $actor, organizationId: $company->getKey());
            DB::afterCommit(function () use ($company, $item) {
                event(new CourseRegistered($company->getKey(), $item->getKey(), $item->student_id, $item->offering_id));
                event(new SeatOffered($company->getKey(), $item->getKey(), $item->student_id, $item->offering_id));
            });

            return;
        }
    }

    /** Credits and overload kept in step; a change after approval asks for it again. */
    private function changed(Organization $company, Registration $registration): void
    {
        $credits = (int) $this->campus->query(RegistrationItem::class, $company)->where('registration_id', $registration->getKey())->where('status', 'registered')->sum('credits_centi');
        $maximum = $this->credits($company, 'max_credits');
        $registration->credits_centi = $credits;
        $registration->overload = $maximum > 0 && $credits > $maximum;
        if ($registration->status === 'approved' && ($this->rule($company, 'approval_required') || $registration->overload)) {
            $registration->fill(['status' => 'submitted', 'approved_at' => null, 'approved_by' => null]);
        }
        $registration->version++;
        $registration->save();
    }

    private function markApproved(Organization $company, Registration $registration, ?User $actor): void
    {
        $registration->fill(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $actor?->getKey()]);
        DB::afterCommit(fn () => event(new RegistrationApproved($company->getKey(), $registration->getKey(), $registration->student_id)));
    }

    private function assertCredits(Organization $company, Registration $registration, int $adding): void
    {
        $maximum = $this->credits($company, 'max_credits');
        if ($maximum === 0) {
            return;
        }
        $limit = $maximum + $this->credits($company, 'overload_credits');
        if ($registration->credits_centi + $adding > $limit) {
            throw RegistrationException::creditsOver($this->creditText($limit));
        }
    }

    private function assertPrerequisites(Organization $company, string $studentId, string $subjectId, string $code): void
    {
        if (! $this->rule($company, 'prerequisites_enforced')) {
            return;
        }
        $needed = $this->academic->prerequisites($company, [$subjectId])[$subjectId] ?? [];
        if ($needed === []) {
            return;
        }
        $completed = $this->campus->query(RegistrationItem::class, $company)->where('student_id', $studentId)->whereIn('subject_id', $needed)
            ->where('outcome', 'completed')->pluck('subject_id')->all();
        $missing = array_values(array_diff($needed, $completed));
        if ($missing !== []) {
            $codes = array_values(array_map(fn (array $subject) => $subject['code'], $this->academic->subjects($company, $missing)));
            throw RegistrationException::prerequisitesMissing($code, $codes);
        }
    }

    /** Students act only while the window is open (adding until it closes, dropping until add/drop ends at the latest). */
    public function assertWindowOpen(Organization $company, string $sessionId, bool $forDrop = false): void
    {
        if (! $this->rule($company, 'self_registration')) {
            throw RegistrationException::selfRegistrationOff();
        }
        $window = $this->window($company, $sessionId);
        $today = $this->campus->today($company)->toDateString();
        $last = $forDrop ? max($window?->closes_on?->toDateString(), $window?->add_drop_until?->toDateString()) : $window?->closes_on?->toDateString();
        if ($window === null || $today < $window->opens_on->toDateString() || $today > $last) {
            throw RegistrationException::windowClosed($window?->opens_on?->toDateString(), $window?->closes_on?->toDateString());
        }
    }

    /** Whether a student may change their registration today (to add, or to drop when $forDrop). */
    public function windowOpen(Organization $company, string $sessionId, bool $forDrop = false): bool
    {
        try {
            $this->assertWindowOpen($company, $sessionId, $forDrop);

            return true;
        } catch (RegistrationException) {
            return false;
        }
    }

    private function locked(Organization $company, Registration $registration, ?int $baseVersion = null): Registration
    {
        /** @var Registration $fresh */
        $fresh = $this->campus->query(Registration::class, $company)->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();
        if ($baseVersion !== null && $fresh->version !== $baseVersion) {
            throw RegistrationException::versionConflict(['version' => $fresh->version]);
        }

        return $fresh;
    }

    private function rule(Organization $company, string $key): bool
    {
        return (bool) $this->rules->get("course_registration.{$key}", $this->contexts->forOrganization($company));
    }

    /** A whole-credit rule in hundredths. */
    private function credits(Organization $company, string $key): int
    {
        return 100 * (int) $this->rules->get("course_registration.{$key}", $this->contexts->forOrganization($company));
    }

    /** Hundredths of a credit as text ("1500" -> "15", "1550" -> "15.5"). */
    public function creditText(int $centi): string
    {
        $whole = intdiv($centi, 100);
        $rest = rtrim(str_pad((string) ($centi % 100), 2, '0', STR_PAD_LEFT), '0');

        return $rest === '' ? (string) $whole : "{$whole}.{$rest}";
    }
}
