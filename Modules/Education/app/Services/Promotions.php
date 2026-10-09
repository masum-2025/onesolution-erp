<?php

namespace Modules\Education\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Education\Events\PromotionApplied;
use Modules\Education\Events\PromotionUndone;
use Modules\Education\Exceptions\EducationException;
use Modules\Education\Models\Enrollment;
use Modules\Education\Models\Level;
use Modules\Education\Models\Program;
use Modules\Education\Models\PromotionBatch;
use Modules\Education\Models\PromotionLine;
use Modules\Education\Models\Section;
use Modules\Education\Models\Session;
use Modules\Education\Models\Student;

/**
 * Promotion lists: moving a session's students on to the next session.
 *
 * 1. A list is made for a level (or one section) of a session, at a campus:
 *    every active student is on it, to be promoted to the next level (or to
 *    graduate on the last one).
 * 2. Each decision can be changed: promote, repeat (more often than the rule
 *    education.max_repeats needs a reason), leave (with a reason) or
 *    graduate (last level only), and the section in the next session.
 * 3. Submitted, it is applied at once, or, with the rule
 *    education.promotion_approval, after another person approves it.
 * 4. Applying ends this session's enrollments (promoted, repeated, left,
 *    graduated) and makes the next session's; every section keeps to its
 *    capacity, rolls follow the rule. All or nothing.
 * 5. Within education.promotion_undo_days it can be undone as a whole, while
 *    the students have not moved on again: the new enrollments go, the old
 *    ones are open again.
 */
class Promotions
{
    private const ENDED_AS = ['promote' => 'promoted', 'repeat' => 'repeated', 'leave' => 'left', 'graduate' => 'graduated'];

    public function __construct(
        private Education $education,
        private Enrollments $enrollments,
        private Numbers $numbers,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{from_session_id: string, to_session_id: string, level_id: string, section_id?: string|null, note?: string|null}  $data
     * @param  list<string>  $unitIds  The campus and the units below it.
     */
    public function create(Organization $company, string $unitId, array $unitIds, array $data, User $actor): PromotionBatch
    {
        return $this->education->transaction($company, function () use ($company, $unitId, $unitIds, $data, $actor) {
            [$from, $to, $level] = $this->checkSessions($company, $data['from_session_id'], $data['to_session_id'], $data['level_id']);
            $sectionId = $data['section_id'] ?? null;
            if ($sectionId !== null) {
                $section = $this->education->find(Section::class, $company, $sectionId, 'section');
                if ($section->session_id !== $from->getKey() || $section->level_id !== $level->getKey() || ! in_array($section->unit_id, $unitIds, true)) {
                    throw EducationException::mismatch('section_id');
                }
            }

            $enrollments = $this->education->query(Enrollment::class, $company)
                ->where('session_id', $from->getKey())->where('level_id', $level->getKey())->where('status', 'active')
                ->whereIn('unit_id', $unitIds)
                ->when($sectionId !== null, fn ($query) => $query->where('section_id', $sectionId))
                ->lockForUpdate()->get();
            if ($enrollments->isEmpty()) {
                throw EducationException::nothingToPromote();
            }
            // A student is on one open list at a time.
            $busy = $this->education->query(PromotionLine::class, $company)->whereIn('from_enrollment_id', $enrollments->pluck('id'))
                ->whereIn('batch_id', $this->education->query(PromotionBatch::class, $company)->whereIn('status', ['draft', 'pending_approval'])->pluck('id'))->exists();
            if ($busy) {
                throw EducationException::promotionOpen();
            }

            $batch = new PromotionBatch;
            $batch->fill([
                'organization_id' => $company->getKey(),
                'unit_id' => $unitId,
                'number' => $this->numbers->promotionNumber($company, (int) $this->education->today($company)->format('Y')),
                'from_session_id' => $from->getKey(),
                'to_session_id' => $to->getKey(),
                'level_id' => $level->getKey(),
                'section_id' => $sectionId,
                'status' => 'draft',
                'note' => $data['note'] ?? null,
                'created_by' => $actor->getKey(),
                'version' => 1,
            ]);
            $batch->save();

            $repeats = $this->repeatsOf($company, $level->getKey(), $enrollments->pluck('student_id')->all());
            foreach ($enrollments as $enrollment) {
                $line = new PromotionLine;
                $line->fill([
                    'organization_id' => $company->getKey(),
                    'batch_id' => $batch->getKey(),
                    'student_id' => $enrollment->student_id,
                    'from_enrollment_id' => $enrollment->getKey(),
                    'decision' => $level->next_level_id !== null ? 'promote' : 'graduate',
                    'to_level_id' => $level->next_level_id,
                    'repeats' => $repeats[$enrollment->student_id] ?? 0,
                ]);
                $line->save();
            }
            $this->audit->record('education.promotion_created', $batch, new: [...$batch->only(['number', 'from_session_id', 'to_session_id', 'level_id', 'section_id']), 'students' => $enrollments->count()], actor: $actor, organizationId: $company->getKey());

            return $batch;
        });
    }

    /**
     * Change decisions of a draft list.
     *
     * @param  list<array{line_id: string, decision: string, to_section_id?: string|null, reason?: string|null}>  $changes
     */
    public function decide(Organization $company, PromotionBatch $batch, int $baseVersion, array $changes, User $actor): PromotionBatch
    {
        return $this->education->transaction($company, function () use ($company, $batch, $baseVersion, $changes, $actor) {
            $batch = $this->locked($company, $batch, $baseVersion, ['draft']);
            $level = $this->education->find(Level::class, $company, $batch->level_id, 'level');
            $lines = $this->lines($company, $batch)->keyBy('id');
            $enrollments = $this->education->query(Enrollment::class, $company)->whereKey($lines->pluck('from_enrollment_id'))->get()->keyBy('id');
            $maxRepeats = (int) $this->rules->get('education.max_repeats', $this->contexts->forOrganization($company));

            foreach ($changes as $index => $change) {
                /** @var PromotionLine|null $line */
                $line = $lines[$change['line_id']] ?? throw EducationException::notFound('line');
                $decision = $change['decision'];
                $reason = trim((string) ($change['reason'] ?? '')) ?: null;
                $toLevel = match ($decision) {
                    'promote' => $level->next_level_id ?? throw ValidationException::withMessages(["lines.{$index}.decision" => __('education::education.validation.no_next_level')]),
                    'repeat' => $level->getKey(),
                    default => null,
                };
                if ($decision === 'graduate' && $level->next_level_id !== null) {
                    throw ValidationException::withMessages(["lines.{$index}.decision" => __('education::education.validation.graduate_last_level')]);
                }
                if ($decision === 'leave' && $reason === null) {
                    throw ValidationException::withMessages(["lines.{$index}.reason" => __('education::education.validation.reason_needed')]);
                }
                if ($decision === 'repeat' && $line->repeats >= $maxRepeats && $reason === null) {
                    throw ValidationException::withMessages(["lines.{$index}.reason" => __('education::education.validation.repeat_reason', ['count' => $line->repeats])]);
                }

                $sectionId = $toLevel === null ? null : ($change['to_section_id'] ?? null);
                if ($sectionId !== null) {
                    $section = $this->education->find(Section::class, $company, $sectionId, 'section');
                    $unit = $enrollments[$line->from_enrollment_id]?->unit_id;
                    if ($section->session_id !== $batch->to_session_id || $section->level_id !== $toLevel || $section->unit_id !== $unit || ! $section->is_active) {
                        throw ValidationException::withMessages(["lines.{$index}.to_section_id" => __('education::education.validation.reference')]);
                    }
                }
                $line->fill(['decision' => $decision, 'to_level_id' => $toLevel, 'to_section_id' => $sectionId, 'reason' => $reason])->save();
            }

            $batch->forceFill(['version' => $batch->version + 1])->save();
            $this->audit->record('education.promotion_decided', $batch, new: ['changed' => count($changes)], actor: $actor, organizationId: $company->getKey());

            return $batch;
        });
    }

    /**
     * Placing many at once: every student promoted (or repeating) into this
     * section, until it is full; the rest keep theirs.
     */
    public function placeAll(Organization $company, PromotionBatch $batch, int $baseVersion, string $sectionId, User $actor): PromotionBatch
    {
        $lines = $this->lines($company, $batch)->filter(fn (PromotionLine $line) => $line->to_level_id !== null && $line->to_section_id === null);
        $changes = $lines->map(fn (PromotionLine $line) => ['line_id' => $line->getKey(), 'decision' => $line->decision, 'to_section_id' => $sectionId, 'reason' => $line->reason])->values()->all();

        return $this->decide($company, $batch, $baseVersion, $changes, $actor);
    }

    /** Hand in a draft: applied now, or waiting for a second person (rule education.promotion_approval). */
    public function submit(Organization $company, PromotionBatch $batch, int $baseVersion, User $actor): PromotionBatch
    {
        return $this->education->transaction($company, function () use ($company, $batch, $baseVersion, $actor) {
            $batch = $this->locked($company, $batch, $baseVersion, ['draft']);
            $batch->forceFill(['submitted_by' => $actor->getKey()]);
            if ((bool) $this->rules->get('education.promotion_approval', $this->contexts->forOrganization($company))) {
                $batch->forceFill(['status' => 'pending_approval', 'version' => $batch->version + 1])->save();
                $this->audit->record('education.promotion_submitted', $batch, new: ['number' => $batch->number], actor: $actor, organizationId: $company->getKey());

                return $batch;
            }

            return $this->apply($company, $batch, $actor);
        });
    }

    /** A second person approves: never the one who made or handed in the list. */
    public function approve(Organization $company, PromotionBatch $batch, int $baseVersion, User $actor): PromotionBatch
    {
        return $this->education->transaction($company, function () use ($company, $batch, $baseVersion, $actor) {
            $batch = $this->locked($company, $batch, $baseVersion, ['pending_approval']);
            if (in_array($actor->getKey(), [$batch->created_by, $batch->submitted_by], true)) {
                throw EducationException::ownPromotion();
            }
            $batch->forceFill(['approved_by' => $actor->getKey()]);

            return $this->apply($company, $batch, $actor);
        });
    }

    /** Sent back to be changed (approver), with a note. */
    public function reject(Organization $company, PromotionBatch $batch, int $baseVersion, string $note, User $actor): PromotionBatch
    {
        return $this->education->transaction($company, function () use ($company, $batch, $baseVersion, $note, $actor) {
            $batch = $this->locked($company, $batch, $baseVersion, ['pending_approval']);
            $batch->forceFill(['status' => 'draft', 'note' => $note, 'submitted_by' => null, 'version' => $batch->version + 1])->save();
            $this->audit->record('education.promotion_rejected', $batch, new: ['note' => $note], actor: $actor, organizationId: $company->getKey());

            return $batch;
        });
    }

    public function cancel(Organization $company, PromotionBatch $batch, int $baseVersion, User $actor): PromotionBatch
    {
        return $this->education->transaction($company, function () use ($company, $batch, $baseVersion, $actor) {
            $batch = $this->locked($company, $batch, $baseVersion, ['draft', 'pending_approval']);
            $batch->forceFill(['status' => 'cancelled', 'version' => $batch->version + 1])->save();
            $this->audit->record('education.promotion_cancelled', $batch, actor: $actor, organizationId: $company->getKey());

            return $batch;
        });
    }

    /** Undo an applied list as a whole, within the rule's days, while nothing has moved on since. */
    public function undo(Organization $company, PromotionBatch $batch, int $baseVersion, User $actor): PromotionBatch
    {
        return $this->education->transaction($company, function () use ($company, $batch, $baseVersion, $actor) {
            $batch = $this->locked($company, $batch, $baseVersion, ['applied']);
            if ($batch->undo_until === null || $this->education->today($company)->gt($batch->undo_until)) {
                throw EducationException::undoTooLate();
            }
            $lines = $this->lines($company, $batch);
            $results = $this->education->query(Enrollment::class, $company)->whereKey($lines->pluck('result_enrollment_id')->filter())->lockForUpdate()->get()->keyBy('id');
            $students = $this->education->query(Student::class, $company)->whereKey($lines->pluck('student_id'))->lockForUpdate()->get()->keyBy('id');

            foreach ($lines as $line) {
                $result = $line->result_enrollment_id === null ? null : ($results[$line->result_enrollment_id] ?? null);
                $moved = $result !== null
                    ? $result->status !== 'active'
                    : in_array($line->decision, ['leave', 'graduate'], true) && ! in_array($students[$line->student_id]?->status, ['left', 'graduated'], true);
                if ($moved) {
                    throw EducationException::undoMovedOn();
                }
            }

            foreach ($lines as $line) {
                if ($line->result_enrollment_id !== null) {
                    $results[$line->result_enrollment_id]->delete();
                }
                $this->education->query(Enrollment::class, $company)->whereKey($line->from_enrollment_id)->update(['status' => 'active', 'ended_on' => null, 'promotion_line_id' => null]);
                if (in_array($line->decision, ['leave', 'graduate'], true)) {
                    $student = $students[$line->student_id];
                    $student->forceFill(['status' => 'active', 'left_on' => null, 'left_reason' => null, 'version' => $student->version + 1])->save();
                }
                $line->forceFill(['result_enrollment_id' => null])->save();
            }

            $batch->forceFill(['status' => 'undone', 'undone_at' => now(), 'undone_by' => $actor->getKey(), 'version' => $batch->version + 1])->save();
            $this->audit->record('education.promotion_undone', $batch, new: ['number' => $batch->number, 'students' => $lines->count()], actor: $actor, organizationId: $company->getKey());
            $event = new PromotionUndone($company->getKey(), $batch->getKey());
            DB::afterCommit(fn () => event($event));

            return $batch;
        });
    }

    /** @return Collection<int, PromotionLine> */
    public function lines(Organization $company, PromotionBatch $batch): Collection
    {
        return $this->education->query(PromotionLine::class, $company)->where('batch_id', $batch->getKey())->orderBy('created_at')->orderBy('id')->get();
    }

    /** All or nothing: every line, or none. Inside the caller's transaction. */
    private function apply(Organization $company, PromotionBatch $batch, User $actor): PromotionBatch
    {
        $from = $this->education->find(Session::class, $company, $batch->from_session_id, 'session');
        $to = $this->education->find(Session::class, $company, $batch->to_session_id, 'session');
        $lines = $this->lines($company, $batch);
        $old = $this->education->query(Enrollment::class, $company)->whereKey($lines->pluck('from_enrollment_id'))->lockForUpdate()->get()->keyBy('id');
        $students = $this->education->query(Student::class, $company)->whereKey($lines->pluck('student_id'))->lockForUpdate()->get()->keyBy('id');

        foreach ($lines as $line) {
            $enrollment = $old[$line->from_enrollment_id] ?? null;
            if ($enrollment === null || $enrollment->status !== 'active') {
                throw EducationException::changedSince();
            }
        }

        foreach ($lines as $line) {
            $enrollment = $old[$line->from_enrollment_id];
            $enrollment->forceFill(['status' => self::ENDED_AS[$line->decision], 'ended_on' => $from->ends_on->toDateString(), 'promotion_line_id' => $line->getKey(), 'version' => $enrollment->version + 1])->save();

            if ($line->to_level_id !== null) {
                $student = $students[$line->student_id];
                $next = $this->enrollments->enroll($company, $student, $to->getKey(), $line->to_level_id, $line->to_section_id, $to->starts_on);
                $next->forceFill(['promotion_line_id' => $line->getKey()])->save();
                $line->forceFill(['result_enrollment_id' => $next->getKey()])->save();
            } else {
                $students[$line->student_id]->forceFill([
                    'status' => $line->decision === 'graduate' ? 'graduated' : 'left',
                    'left_on' => $from->ends_on->toDateString(),
                    'left_reason' => $line->reason ?? ($line->decision === 'graduate' ? __('education::education.statuses.graduated') : null),
                    'version' => $students[$line->student_id]->version + 1,
                ])->save();
            }
        }

        $days = (int) $this->rules->get('education.promotion_undo_days', $this->contexts->forOrganization($company));
        $batch->forceFill([
            'status' => 'applied',
            'applied_at' => now(),
            'undo_until' => $this->education->today($company)->addDays($days)->toDateString(),
            'version' => $batch->version + 1,
        ])->save();

        $counts = $lines->countBy('decision')->all();
        $this->audit->record('education.promotion_applied', $batch, new: ['number' => $batch->number, 'decisions' => $counts, 'approved_by' => $batch->approved_by], actor: $actor, organizationId: $company->getKey());
        $event = new PromotionApplied($company->getKey(), $batch->getKey());
        DB::afterCommit(fn () => event($event));

        return $batch;
    }

    /**
     * Both sessions of the program's kind, the next one later and open, and the level of that program.
     *
     * @return array{0: Session, 1: Session, 2: Level}
     */
    private function checkSessions(Organization $company, string $fromId, string $toId, string $levelId): array
    {
        /** @var Session $from */
        $from = $this->education->find(Session::class, $company, $fromId, 'session');
        /** @var Session $to */
        $to = $this->education->find(Session::class, $company, $toId, 'session');
        /** @var Level $level */
        $level = $this->education->find(Level::class, $company, $levelId, 'level');
        $program = $this->education->find(Program::class, $company, $level->program_id, 'program');

        if ($from->kind !== $program->progression || $to->kind !== $program->progression || $to->status === 'closed' || $to->starts_on->lte($from->starts_on)) {
            throw EducationException::mismatch('to_session_id');
        }

        return [$from, $to, $level];
    }

    /**
     * How often each student repeated this level before.
     *
     * @param  list<string>  $studentIds
     * @return array<string, int>
     */
    private function repeatsOf(Organization $company, string $levelId, array $studentIds): array
    {
        return $this->education->query(Enrollment::class, $company)->where('level_id', $levelId)->where('status', 'repeated')
            ->whereIn('student_id', $studentIds)->get(['student_id'])->countBy('student_id')->all();
    }

    /** @param list<string> $statuses */
    private function locked(Organization $company, PromotionBatch $batch, int $baseVersion, array $statuses): PromotionBatch
    {
        /** @var PromotionBatch $fresh */
        $fresh = $this->education->query(PromotionBatch::class, $company)->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw EducationException::versionConflict(['version' => $fresh->version]);
        }
        if (! in_array($fresh->status, $statuses, true)) {
            throw EducationException::wrongStatus($fresh->status);
        }

        return $fresh;
    }
}
