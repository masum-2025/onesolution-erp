<?php

namespace Modules\Attendance\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Attendance\Exceptions\AttendanceException;
use Modules\Attendance\Models\Holiday;
use Modules\Attendance\Models\Roster;
use Modules\Attendance\Models\Shift;
use Modules\Hrm\Directory\EmployeeRecord;

/**
 * A company's working hours: shifts, holidays, and who works which shift
 * from when (rosters). Changes are audited (they decide lateness, absence
 * and over time, which pay follows).
 */
class Schedules
{
    public function __construct(private Workplace $workplace, private AuditLogger $audit) {}

    /**
     * @param  array{code: string, name: array<string, string>, start_minute: int, end_minute: int, break_minutes?: int}  $data
     */
    public function createShift(Organization $company, array $data, User $actor): Shift
    {
        return $this->workplace->transaction($company, function () use ($company, $data, $actor) {
            $this->assertCodeFree($company, $data['code'], null);
            $shift = new Shift;
            $shift->fill([
                'organization_id' => $company->getKey(), 'code' => $data['code'], 'start_minute' => $data['start_minute'],
                'end_minute' => $data['end_minute'], 'break_minutes' => $data['break_minutes'] ?? 0, 'is_active' => true, 'version' => 1,
            ]);
            $shift->putTexts('name', $data['name']);
            $this->assertBreakFits($shift);
            $shift->save();
            $this->audit->record('attendance.shift_created', $shift, new: $this->shiftValues($shift), actor: $actor, organizationId: $company->getKey());

            return $shift;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateShift(Organization $company, Shift $shift, int $baseVersion, array $data, User $actor): Shift
    {
        return $this->workplace->transaction($company, function () use ($company, $shift, $baseVersion, $data, $actor) {
            /** @var Shift $shift */
            $shift = $this->workplace->query(Shift::class, $company)->whereKey($shift->getKey())->lockForUpdate()->firstOrFail();
            if ($shift->version !== $baseVersion) {
                throw AttendanceException::versionConflict(['version' => $shift->version]);
            }
            if (isset($data['code'])) {
                $this->assertCodeFree($company, $data['code'], $shift->getKey());
            }
            $old = $this->shiftValues($shift);
            $shift->fill(array_intersect_key($data, array_flip(['code', 'start_minute', 'end_minute', 'break_minutes', 'is_active'])));
            if (isset($data['name'])) {
                $shift->putTexts('name', $data['name']);
            }
            $this->assertBreakFits($shift);
            $shift->version++;
            $shift->save();
            $this->audit->record('attendance.shift_updated', $shift, old: $old, new: $this->shiftValues($shift), actor: $actor, organizationId: $company->getKey());

            return $shift;
        });
    }

    /**
     * @param  array{on: string, name: array<string, string>, unit_id?: string|null}  $data
     */
    public function addHoliday(Organization $company, array $data, User $actor): Holiday
    {
        return $this->workplace->transaction($company, function () use ($company, $data, $actor) {
            $holiday = new Holiday;
            $holiday->fill(['organization_id' => $company->getKey(), 'unit_id' => $data['unit_id'] ?? null, 'on' => $data['on']]);
            $holiday->putTexts('name', $data['name']);
            $holiday->save();
            $this->audit->record('attendance.holiday_added', $holiday, new: ['on' => $data['on'], 'unit_id' => $holiday->unit_id], actor: $actor, organizationId: $company->getKey());

            return $holiday;
        });
    }

    public function removeHoliday(Organization $company, Holiday $holiday, User $actor): void
    {
        $this->workplace->transaction($company, function () use ($company, $holiday, $actor) {
            $this->audit->record('attendance.holiday_removed', $holiday, old: ['on' => $holiday->on->toDateString(), 'unit_id' => $holiday->unit_id], actor: $actor, organizationId: $company->getKey());
            $holiday->delete();
        });
    }

    /**
     * Put employees on a shift (or none) from a day: the roster each had
     * then ends the day before; one starting later is replaced.
     *
     * @param  list<EmployeeRecord>  $employees
     */
    public function assign(Organization $company, array $employees, ?Shift $shift, CarbonImmutable $from, User $actor): int
    {
        if ($shift !== null && ! $shift->is_active) {
            throw ValidationException::withMessages(['shift_id' => __('attendance::attendance.validation.shift_inactive')]);
        }

        return $this->workplace->transaction($company, function () use ($company, $employees, $shift, $from, $actor) {
            $date = $from->toDateString();
            foreach ($employees as $employee) {
                $rosters = $this->workplace->query(Roster::class, $company)->where('employee_id', $employee->id);
                (clone $rosters)->where('from', '>=', $date)->delete();
                (clone $rosters)->where('from', '<', $date)->where(fn ($query) => $query->whereNull('to')->orWhere('to', '>=', $date))
                    ->update(['to' => $from->subDay()->toDateString()]);

                $roster = new Roster;
                $roster->fill(['organization_id' => $company->getKey(), 'employee_id' => $employee->id, 'shift_id' => $shift?->getKey(), 'from' => $date, 'created_by' => $actor->getKey()])->save();
            }
            $this->audit->record('attendance.roster_assigned', $shift ?? $company, new: [
                'employees' => array_map(fn (EmployeeRecord $employee) => $employee->id, $employees), 'shift' => $shift?->code, 'from' => $date,
            ], actor: $actor, organizationId: $company->getKey());

            return count($employees);
        });
    }

    private function assertCodeFree(Organization $company, string $code, ?string $except): void
    {
        $taken = $this->workplace->query(Shift::class, $company)->where('code', $code)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['code' => __('attendance::attendance.validation.code_taken')]);
        }
    }

    private function assertBreakFits(Shift $shift): void
    {
        if ($shift->break_minutes >= $shift->lengthMinutes()) {
            throw ValidationException::withMessages(['break_minutes' => __('attendance::attendance.validation.break_too_long')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function shiftValues(Shift $shift): array
    {
        return [
            'code' => $shift->code, 'start_minute' => $shift->start_minute, 'end_minute' => $shift->end_minute,
            'break_minutes' => $shift->break_minutes, 'is_active' => $shift->is_active,
        ];
    }
}
