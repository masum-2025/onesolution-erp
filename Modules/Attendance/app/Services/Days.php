<?php

namespace Modules\Attendance\Services;

use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Attendance\Enums\DayStatus;
use Modules\Attendance\Exceptions\AttendanceException;
use Modules\Attendance\Models\AttendanceDay;
use Modules\Attendance\Models\Holiday;
use Modules\Attendance\Models\Punch;
use Modules\Attendance\Models\Roster;
use Modules\Attendance\Models\Shift;
use Modules\Hrm\Directory\EmployeeRecord;

/**
 * Works out and keeps each employee's days (att_days).
 *
 * A day belongs to the shift rostered for it: punches from the rule's
 * minutes before the shift starts (accounting.early_punch_minutes) up to the
 * same time a day later count for it, so a night shift ending the next
 * morning is one day, dated the day it started. Without a shift the local
 * calendar day counts. Weekends (rule attendance.weekend_days at the
 * employee's unit) and holidays of the company or the unit are days off.
 * Days before joining or after leaving are not kept.
 */
class Days
{
    /** Longest range worked out at once. */
    public const MAX_RANGE_DAYS = 62;

    private const WEEKDAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function __construct(
        private Workplace $workplace,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /** Work out one employee's day and keep it (null: not employed that day). */
    public function compute(Organization $company, EmployeeRecord $employee, CarbonImmutable $day, ?CarbonImmutable $now = null): ?AttendanceDay
    {
        $day = CarbonImmutable::parse($day->toDateString(), 'UTC');
        if (! $employee->employedOn($day)) {
            return null;
        }

        $unit = Organization::query()->findOrFail($employee->unitId);
        $context = $this->contexts->forOrganization($unit);
        $shift = $this->shiftOn($company, $employee->id, $day);
        $window = $this->window($company, $shift, $day, (int) $this->rules->get('attendance.early_punch_minutes', $context));

        $punches = $this->workplace->query(Punch::class, $company)->where('employee_id', $employee->id)->whereNull('voided_at')
            ->where('punched_at', '>=', $window[0])->where('punched_at', '<', $window[1])
            ->orderBy('punched_at')->pluck('punched_at')->map(fn ($at) => CarbonImmutable::parse($at, 'UTC'))->values()->all();

        $result = DayCalculator::day(
            $shift === null ? null : [
                'start' => $this->workplace->at($company, $day, $shift->start_minute),
                'end' => $this->workplace->at($company, $shift->overnight() ? $day->addDay() : $day, $shift->end_minute),
                'break' => $shift->break_minutes,
                'expected' => $shift->expectedMinutes(),
            ],
            $this->dayOff($company, $unit, $day, $context),
            $punches,
            $now ?? CarbonImmutable::now(),
            [
                'grace' => (int) $this->rules->get('attendance.late_grace_minutes', $context),
                'half_day' => (int) $this->rules->get('attendance.half_day_after_minutes', $context),
                'overtime_min' => (int) $this->rules->get('attendance.overtime_min_minutes', $context),
            ],
        );

        $row = $this->workplace->query(AttendanceDay::class, $company)->where('employee_id', $employee->id)->where('work_date', $day->toDateString())->first()
            ?? new AttendanceDay(['organization_id' => $company->getKey(), 'employee_id' => $employee->id, 'work_date' => $day->toDateString()]);
        $row->fill([...$result, 'unit_id' => $employee->unitId, 'shift_id' => $shift?->getKey()])->save();

        return $row;
    }

    /**
     * Work out a range of days for some employees (oldest first).
     *
     * @param  list<EmployeeRecord>  $employees
     * @return Collection<int, AttendanceDay>
     */
    public function computeRange(Organization $company, array $employees, CarbonImmutable $from, CarbonImmutable $to, ?CarbonImmutable $now = null): Collection
    {
        if ($from->greaterThan($to) || $from->diffInDays($to) >= self::MAX_RANGE_DAYS) {
            throw AttendanceException::rangeTooLong(self::MAX_RANGE_DAYS);
        }

        $days = collect();
        foreach ($employees as $employee) {
            for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
                if ($row = $this->compute($company, $employee, $day, $now)) {
                    $days->push($row);
                }
            }
        }

        return $days;
    }

    /** Work out again the days a punch may belong to (its local day, and the day before for night shifts). */
    public function touch(Organization $company, EmployeeRecord $employee, CarbonImmutable $instant): void
    {
        $day = $this->workplace->dayOf($company, $instant);
        $this->compute($company, $employee, $day->subDay());
        $this->compute($company, $employee, $day);
    }

    /** The shift rostered for a day, or null (no roster, or a roster without fixed hours). */
    public function shiftOn(Organization $company, string $employeeId, CarbonImmutable $day): ?Shift
    {
        $date = $day->toDateString();
        $roster = $this->workplace->query(Roster::class, $company)->where('employee_id', $employeeId)
            ->where('from', '<=', $date)->where(fn ($query) => $query->whereNull('to')->orWhere('to', '>=', $date))
            ->orderByDesc('from')->first();

        return $roster?->shift_id === null ? null : $this->workplace->query(Shift::class, $company)->find($roster->shift_id);
    }

    /** Weekend or holiday for the unit that day, or null for a working day. */
    public function dayOff(Organization $company, Organization $unit, CarbonImmutable $day, $context = null): ?DayStatus
    {
        $units = [...$unit->ancestorIds(), $unit->getKey()];
        $holiday = $this->workplace->query(Holiday::class, $company)->where('on', $day->toDateString())
            ->where(fn ($query) => $query->whereNull('unit_id')->orWhereIn('unit_id', $units))->exists();
        if ($holiday) {
            return DayStatus::Holiday;
        }

        $weekend = (array) $this->rules->get('attendance.weekend_days', $context ?? $this->contexts->forOrganization($unit));

        return in_array(self::WEEKDAYS[$day->dayOfWeek], $weekend, true) ? DayStatus::Weekend : null;
    }

    /**
     * Instants whose punches count for the day: [from, until).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(Organization $company, ?Shift $shift, CarbonImmutable $day, int $early): array
    {
        $start = $shift === null ? $this->workplace->at($company, $day, 0) : $this->workplace->at($company, $day, $shift->start_minute)->subMinutes($early);

        return [$start, $start->addDay()];
    }
}
