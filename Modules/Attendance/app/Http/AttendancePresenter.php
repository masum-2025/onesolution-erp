<?php

namespace Modules\Attendance\Http;

use App\Platform\Tenancy\Models\Organization;
use Modules\Attendance\Models\AttendanceDay;
use Modules\Attendance\Models\Correction;
use Modules\Attendance\Models\Holiday;
use Modules\Attendance\Models\Location;
use Modules\Attendance\Models\Punch;
use Modules\Attendance\Models\Roster;
use Modules\Attendance\Models\Shift;
use Modules\Hrm\Directory\EmployeeRecord;

/**
 * API shapes of attendance. Instants are ISO 8601 (UTC); the app shows them
 * in the company's time. Days are plain dates.
 */
class AttendancePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function shift(Shift $shift): array
    {
        return [
            'id' => $shift->getKey(),
            'code' => $shift->code,
            'name' => $shift->name,
            'names' => $shift->texts('name'),
            'start_minute' => $shift->start_minute,
            'end_minute' => $shift->end_minute,
            'break_minutes' => $shift->break_minutes,
            'overnight' => $shift->overnight(),
            'expected_minutes' => $shift->expectedMinutes(),
            'is_active' => $shift->is_active,
            'version' => $shift->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function holiday(Holiday $holiday): array
    {
        return [
            'id' => $holiday->getKey(),
            'on' => $holiday->on->toDateString(),
            'name' => $holiday->name,
            'names' => $holiday->texts('name'),
            'unit_id' => $holiday->unit_id,
            'unit_name' => $holiday->unit_id === null ? null : Organization::query()->find($holiday->unit_id)?->displayName(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function roster(Roster $roster, ?Shift $shift): array
    {
        return [
            'id' => $roster->getKey(),
            'employee_id' => $roster->employee_id,
            'shift_id' => $roster->shift_id,
            'shift_code' => $shift?->code,
            'shift_name' => $shift?->name,
            'from' => $roster->from->toDateString(),
            'to' => $roster->to?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function punch(Punch $punch): array
    {
        return [
            'id' => $punch->getKey(),
            'employee_id' => $punch->employee_id,
            'punched_at' => $punch->punched_at->toIso8601String(),
            'source' => $punch->source,
            'note' => $punch->note,
            'voided' => $punch->voided_at !== null,
            'void_reason' => $punch->void_reason,
            // How far from the workplace and how sure the phone was; the point itself is never sent.
            'distance_m' => $punch->distance_m,
            'accuracy_m' => $punch->accuracy_m,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function location(Location $location, ?string $unitName = null): array
    {
        return [
            'id' => $location->getKey(),
            'unit_id' => $location->unit_id,
            'unit_name' => $unitName,
            'name' => $location->name,
            'latitude_micro' => $location->latitude_micro,
            'longitude_micro' => $location->longitude_micro,
            'radius_m' => $location->radius_m,
            'is_active' => $location->is_active,
            'version' => $location->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function day(AttendanceDay $day, ?EmployeeRecord $employee = null): array
    {
        return [
            'employee_id' => $day->employee_id,
            ...($employee === null ? [] : ['employee_name' => $employee->name, 'employee_code' => $employee->code]),
            'work_date' => $day->work_date->toDateString(),
            'status' => $day->status->value,
            'shift_id' => $day->shift_id,
            'first_in_at' => $day->first_in_at?->toIso8601String(),
            'last_out_at' => $day->last_out_at?->toIso8601String(),
            'worked_minutes' => $day->worked_minutes,
            'late_minutes' => $day->late_minutes,
            'overtime_minutes' => $day->overtime_minutes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function correction(Correction $correction, ?EmployeeRecord $employee = null, bool $canDecide = false): array
    {
        return [
            'id' => $correction->getKey(),
            'employee_id' => $correction->employee_id,
            ...($employee === null ? [] : ['employee_name' => $employee->name, 'employee_code' => $employee->code]),
            'work_date' => $correction->work_date->toDateString(),
            'in_at' => $correction->in_at?->toIso8601String(),
            'out_at' => $correction->out_at?->toIso8601String(),
            'reason' => $correction->reason,
            'status' => $correction->status,
            'decision_note' => $correction->decision_note,
            'mine' => $correction->requested_by === auth()->id(),
            'can_decide' => $canDecide,
            'version' => $correction->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function employee(EmployeeRecord $employee): array
    {
        return ['id' => $employee->id, 'code' => $employee->code, 'name' => $employee->name, 'unit_id' => $employee->unitId];
    }
}
