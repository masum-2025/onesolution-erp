<?php

namespace Modules\StudentAttendance\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Education\Directory\AcademicDirectory;
use Modules\StudentAttendance\Events\SheetSaved;
use Modules\StudentAttendance\Exceptions\StudentAttendanceException;
use Modules\StudentAttendance\Models\Mark;
use Modules\StudentAttendance\Models\Period;
use Modules\StudentAttendance\Models\Sheet;

/**
 * Taking a section's attendance.
 *
 * - Once a day (rule student_attendance.by_period off): the class teacher
 *   (student_attendance.take) or someone who manages attendance.
 * - By period (rule on): one sheet per period, by a teacher of that
 *   subject in the section, the class teacher, or a manager.
 * - Never a day to come; a teacher goes back at most the rule's
 *   student_attendance.edit_days, a manager any day of the session.
 * - A sheet is saved whole: students left out stay as they were (present
 *   on a new sheet); a student not in the section is refused. op_id makes a
 *   resent sheet harmless; base_version (none for a new one) refuses a
 *   sheet someone else saved meanwhile, offline or not.
 * - A changed sheet, and one taken for an earlier day, are audited.
 */
class Sheets
{
    public function __construct(
        private Register $register,
        private Calendar $calendar,
        private AcademicDirectory $academic,
        private AuditLogger $audit,
    ) {}

    public function byPeriod(Organization $company, string $unitId): bool
    {
        return (bool) $this->register->rule('student_attendance.by_period', $company, $unitId);
    }

    /**
     * Sections whose attendance a person sees from a unit: null for all
     * (who manages attendance, or Education lets them see every student),
     * otherwise their own sections (class or subject teacher).
     *
     * @return list<string>|null
     */
    public function visibleSections(Organization $company, Organization $unit, User $user): ?array
    {
        return Gate::forUser($user)->allows('student_attendance.manage', $unit) ? null : $this->academic->visibleSections($company, $unit, $user);
    }

    /** @return array<string, mixed> A section at the unit or below it. */
    public function section(Organization $company, Organization $unit, string $sectionId): array
    {
        $section = $this->academic->section($company, $sectionId);
        if ($section === null || ! in_array($section['unit_id'], $this->register->subtreeIds($unit), true)) {
            throw StudentAttendanceException::notFound('section');
        }

        return $section;
    }

    /**
     * Subjects a person teaches in a section (empty when they teach none or are no teacher).
     *
     * @param  array<string, mixed>  $section
     * @return list<string>
     */
    public function mySubjects(Organization $company, User $user, array $section): array
    {
        $employee = $this->academic->employeeOf($company, $user);

        return $employee === null ? [] : collect($this->academic->teaching($company, $employee, [$section['unit_id']]))
            ->where('section_id', $section['id'])->pluck('subject_id')->unique()->values()->all();
    }

    /** @param  array<string, mixed>  $section */
    public function isClassTeacher(Organization $company, User $user, array $section): bool
    {
        $employee = $this->academic->employeeOf($company, $user);

        return $employee !== null && $section['class_teacher_id'] === $employee;
    }

    /**
     * Whether a person may take this section's attendance (by period: of this subject; null subject: any of theirs).
     *
     * @param  array<string, mixed>  $section
     */
    public function mayTake(Organization $company, Organization $unit, User $user, array $section, ?string $subjectId, bool $byPeriod): bool
    {
        if (Gate::forUser($user)->allows('student_attendance.manage', $unit)) {
            return true;
        }
        if (! Gate::forUser($user)->allows('student_attendance.take', $unit)) {
            return false;
        }
        if ($this->isClassTeacher($company, $user, $section)) {
            return true;
        }
        if (! $byPeriod) {
            return false;
        }
        $mine = $this->mySubjects($company, $user, $section);

        return $subjectId === null ? $mine !== [] : in_array($subjectId, $mine, true);
    }

    /**
     * Whether a day may be taken or changed now by this person (no day to
     * come; inside the section's session; a teacher only the rule's days back).
     *
     * @param  array<string, mixed>  $section
     */
    public function checkDay(Organization $company, Organization $unit, User $user, array $section, CarbonImmutable $day): void
    {
        $today = $this->register->today($company);
        if ($day->greaterThan($today)) {
            throw StudentAttendanceException::futureDay();
        }
        $session = $this->academic->session($company, $section['session_id']);
        if ($session !== null && ($day->toDateString() < $session['starts_on'] || $day->toDateString() > $session['ends_on'])) {
            throw StudentAttendanceException::outsideSession();
        }
        if (! Gate::forUser($user)->allows('student_attendance.manage', $unit)) {
            $days = (int) $this->register->rule('student_attendance.edit_days', $company, $section['unit_id']);
            if ($day->lessThan($today->subDays($days))) {
                throw StudentAttendanceException::outsideWindow($days);
            }
        }
    }

    /**
     * A section's sheet of a day (and period): who is in the section with
     * their state (present when nothing is saved yet), whether the day is off,
     * and whether the person may save it.
     *
     * @return array<string, mixed>
     */
    public function open(Organization $company, Organization $unit, User $user, string $sectionId, CarbonImmutable $day, ?string $periodId): array
    {
        $section = $this->section($company, $unit, $sectionId);
        $byPeriod = $this->byPeriod($company, $section['unit_id']);
        $period = $this->period($company, $section, $byPeriod, $periodId);
        $sheet = $this->register->query(Sheet::class, $company)->where('section_id', $section['id'])
            ->whereDate('taken_on', $day->toDateString())->where('slot', $period?->getKey() ?? Sheet::DAY)->first();
        $marks = $sheet === null ? collect() : $this->register->query(Mark::class, $company)->where('sheet_id', $sheet->getKey())->get()->keyBy('student_id');
        $subjects = $byPeriod ? $this->mySubjects($company, $user, $section) : [];
        $mayTake = $this->mayTake($company, $unit, $user, $section, $sheet?->subject_id, $byPeriod);
        if ($mayTake) {
            try {
                $this->checkDay($company, $unit, $user, $section, $day);
            } catch (StudentAttendanceException) {
                $mayTake = false;
            }
        }

        return [
            'section' => $section,
            'by_period' => $byPeriod,
            'period' => $period,
            'sheet' => $sheet,
            'day_off' => $this->calendar->dayOff($company, $section['unit_id'], $section['level_id'], $day),
            'may_take' => $mayTake,
            'my_subjects' => $subjects,
            'students' => $this->roster($company, $section, $marks->keys()->all())->map(fn (array $student) => [
                ...$student,
                'status' => $marks[$student['id']]->status ?? 'present',
                'note' => $marks[$student['id']]->note ?? null,
            ])->values()->all(),
        ];
    }

    /**
     * Save a section's sheet of a day: [the sheet, whether it was new].
     *
     * @param  array<string, mixed>  $data  period_id, subject_id, marks [{student_id, status, note}], op_id, base_version
     * @return array{0: Sheet, 1: bool}
     */
    public function save(Organization $company, Organization $unit, User $actor, string $sectionId, CarbonImmutable $day, array $data): array
    {
        if (($data['op_id'] ?? null) !== null) {
            $done = $this->register->query(Sheet::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return [$done, false];
            }
        }
        $section = $this->section($company, $unit, $sectionId);
        $byPeriod = $this->byPeriod($company, $section['unit_id']);
        $period = $this->period($company, $section, $byPeriod, $data['period_id'] ?? null);
        if ($byPeriod && $period === null) {
            throw StudentAttendanceException::periodNeeded();
        }
        if ($period !== null && ! $period->is_active) {
            throw StudentAttendanceException::periodInactive();
        }
        $subjectId = $byPeriod ? ($data['subject_id'] ?? null) : null;
        if ($subjectId !== null && ! in_array($subjectId, array_column($this->academic->curriculum($company, $section['level_id'], $section['session_id']), 'subject_id'), true)) {
            throw StudentAttendanceException::subjectNotInSection();
        }
        if ($byPeriod && $subjectId === null && ! Gate::forUser($actor)->allows('student_attendance.manage', $unit)) {
            // A teacher of one subject there need not pick it.
            $mine = $this->mySubjects($company, $actor, $section);
            $subjectId = count($mine) === 1 ? $mine[0] : null;
        }
        if (! $this->mayTake($company, $unit, $actor, $section, $subjectId, $byPeriod)) {
            throw StudentAttendanceException::notYourSection();
        }
        $this->checkDay($company, $unit, $actor, $section, $day);
        $given = collect($data['marks'] ?? [])->keyBy('student_id');
        $roster = collect($this->academic->sectionStudents($company, $section['id']))->pluck('id')->all();
        $slot = $period?->getKey() ?? Sheet::DAY;

        try {
            $saved = $this->register->transaction($company, function () use ($company, $actor, $section, $day, $data, $period, $subjectId, $byPeriod, $given, $roster, $slot) {
                $sheet = $this->register->query(Sheet::class, $company)->where('section_id', $section['id'])
                    ->whereDate('taken_on', $day->toDateString())->where('slot', $slot)->lockForUpdate()->first();
                $base = isset($data['base_version']) ? (int) $data['base_version'] : null;
                if ($sheet !== null && $sheet->version !== $base) {
                    throw StudentAttendanceException::versionConflict(['version' => $sheet->version, 'id' => $sheet->getKey()]);
                }
                $existing = $sheet === null ? collect() : $this->register->query(Mark::class, $company)->where('sheet_id', $sheet->getKey())->get()->keyBy('student_id');
                $allowed = array_values(array_unique([...$roster, ...$existing->keys()->all()]));
                $unknown = array_values(array_diff($given->keys()->all(), $allowed));
                if ($unknown !== []) {
                    throw StudentAttendanceException::studentNotInSection($unknown);
                }

                $final = [];
                foreach ($allowed as $studentId) {
                    $mark = $given[$studentId] ?? null;
                    $final[$studentId] = [
                        'status' => $mark['status'] ?? $existing[$studentId]->status ?? 'present',
                        'note' => $mark !== null && array_key_exists('note', $mark) ? (trim((string) $mark['note']) ?: null) : ($existing[$studentId]->note ?? null),
                    ];
                }
                $counts = array_count_values(array_column($final, 'status'));
                $created = $sheet === null;
                $teacher = $byPeriod && $subjectId !== null
                    ? (collect($this->academic->subjectTeachers($company, [$section['id']]))->firstWhere('subject_id', $subjectId)['teacher_id'] ?? null)
                    : ($byPeriod ? null : $section['class_teacher_id']);
                $sheet ??= new Sheet([
                    'organization_id' => $company->getKey(), 'unit_id' => $section['unit_id'], 'session_id' => $section['session_id'],
                    'section_id' => $section['id'], 'taken_on' => $day->toDateString(), 'slot' => $slot, 'period_id' => $period?->getKey(),
                    'op_id' => $data['op_id'] ?? null,
                ]);
                $sheet->fill([
                    'subject_id' => $subjectId ?? $sheet->subject_id, 'teacher_id' => $teacher ?? $sheet->teacher_id, 'taken_by' => $actor->getKey(),
                    'present_count' => $counts['present'] ?? 0, 'absent_count' => $counts['absent'] ?? 0,
                    'late_count' => $counts['late'] ?? 0, 'leave_count' => $counts['leave'] ?? 0,
                ]);
                if (! $created) {
                    $sheet->version++;
                }
                $sheet->save();

                $changes = [];
                foreach ($final as $studentId => $state) {
                    $mark = $existing[$studentId] ?? null;
                    if ($mark === null) {
                        (new Mark(['organization_id' => $company->getKey(), 'sheet_id' => $sheet->getKey(), 'student_id' => $studentId,
                            'taken_on' => $day->toDateString(), ...$state]))->save();
                    } elseif ($mark->status !== $state['status'] || $mark->note !== $state['note']) {
                        $changes[$studentId] = [$mark->status, $state['status']];
                        $mark->fill($state)->save();
                    }
                }

                $facts = ['date' => $day->toDateString(), 'section_id' => $section['id'], 'slot' => $slot, 'subject_id' => $sheet->subject_id];
                if (! $created && $changes !== []) {
                    $this->audit->record('student_attendance.changed', $sheet,
                        old: [...$facts, 'marks' => array_map(fn (array $change) => $change[0], $changes)],
                        new: [...$facts, 'marks' => array_map(fn (array $change) => $change[1], $changes)],
                        actor: $actor, organizationId: $company->getKey());
                } elseif ($created && $day->lessThan($this->register->today($company))) {
                    $this->audit->record('student_attendance.taken_late', $sheet, new: [...$facts, 'counts' => $counts], actor: $actor, organizationId: $company->getKey());
                }

                return [$sheet, $created];
            });
        } catch (UniqueConstraintViolationException) {
            // Someone saved the same section, day and slot at the same moment.
            $other = $this->register->query(Sheet::class, $company)->where('section_id', $section['id'])->whereDate('taken_on', $day->toDateString())->where('slot', $slot)->first();

            throw StudentAttendanceException::versionConflict(['version' => $other?->version, 'id' => $other?->getKey()]);
        }
        // Today's sheet: families may be told about absences (rule student_attendance.notify_absent, queued).
        if ($day->equalTo($this->register->today($company))) {
            SheetSaved::dispatch($company->getKey(), $saved[0]->getKey());
        }

        return $saved;
    }

    /**
     * Sheets of these sections on a day, by section.
     *
     * @param  list<string>  $sectionIds
     * @return Collection<string, Collection<int, Sheet>>
     */
    public function sheetsOn(Organization $company, array $sectionIds, CarbonImmutable $day): Collection
    {
        return $this->register->query(Sheet::class, $company)->whereIn('section_id', $sectionIds)
            ->whereDate('taken_on', $day->toDateString())->get()->groupBy('section_id');
    }

    /**
     * The section's students now, and any marked on the sheet who have left
     * it, with roll numbers, in roll order (then by name).
     *
     * @param  array<string, mixed>  $section
     * @param  list<string>  $markedIds
     * @return Collection<int, array<string, mixed>>
     */
    public function roster(Organization $company, array $section, array $markedIds = []): Collection
    {
        $students = collect($this->academic->sectionStudents($company, $section['id']))->keyBy('id');
        $left = array_values(array_diff($markedIds, $students->keys()->all()));
        if ($left !== []) {
            $students = $students->union($this->academic->students($company, $left));
        }
        $rolls = $this->academic->rollNumbers($company, $section['session_id'], $students->keys()->all());

        return $students->map(fn (array $student) => [
            'id' => $student['id'], 'name' => $student['name'], 'name_local' => $student['name_local'] ?? null,
            'code' => $student['code'] ?? null, 'roll' => $rolls[$student['id']] ?? null,
        ])->sortBy([
            fn (array $a, array $b) => ($a['roll'] === null) <=> ($b['roll'] === null),
            fn (array $a, array $b) => strnatcmp((string) $a['roll'], (string) $b['roll']),
            fn (array $a, array $b) => strcmp($a['name'], $b['name']),
        ])->values();
    }

    /** The period a sheet is for (by period only; one at the section's campus). */
    private function period(Organization $company, array $section, bool $byPeriod, ?string $periodId): ?Period
    {
        if ($periodId === null) {
            return null;
        }
        if (! $byPeriod) {
            throw StudentAttendanceException::periodNotUsed();
        }

        return $this->register->query(Period::class, $company)->whereKey($periodId)->where('unit_id', $section['unit_id'])->first()
            ?? throw StudentAttendanceException::notFound('period');
    }
}
