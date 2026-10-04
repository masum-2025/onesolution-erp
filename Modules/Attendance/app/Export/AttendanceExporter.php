<?php

namespace Modules\Attendance\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Modules\Attendance\Models\AttendanceDay;
use Modules\Attendance\Models\Correction;
use Modules\Attendance\Models\Holiday;
use Modules\Attendance\Models\Punch;
use Modules\Attendance\Models\Roster;
use Modules\Attendance\Models\Shift;

/**
 * Attendance in the client's data export: shifts, holidays, rosters,
 * punches (voided ones marked), days and corrections. Runs without a tenant
 * context, reading the client's own database for the organizations given.
 */
class AttendanceExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'attendance';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        return [
            'shifts' => $this->rows(Shift::class, $organization, $organizationIds, fn (Shift $shift) => [
                'id' => $shift->getKey(), 'organization_id' => $shift->organization_id, 'code' => $shift->code,
                'name' => json_encode($shift->texts('name'), JSON_UNESCAPED_UNICODE), 'start_minute' => $shift->start_minute,
                'end_minute' => $shift->end_minute, 'break_minutes' => $shift->break_minutes, 'is_active' => $shift->is_active,
            ]),
            'holidays' => $this->rows(Holiday::class, $organization, $organizationIds, fn (Holiday $holiday) => [
                'id' => $holiday->getKey(), 'organization_id' => $holiday->organization_id, 'unit_id' => $holiday->unit_id,
                'on' => $holiday->on->toDateString(), 'name' => json_encode($holiday->texts('name'), JSON_UNESCAPED_UNICODE),
            ]),
            'rosters' => $this->rows(Roster::class, $organization, $organizationIds, fn (Roster $roster) => [
                'id' => $roster->getKey(), 'employee_id' => $roster->employee_id, 'shift_id' => $roster->shift_id,
                'from' => $roster->from->toDateString(), 'to' => $roster->to?->toDateString(),
            ]),
            'punches' => $this->rows(Punch::class, $organization, $organizationIds, fn (Punch $punch) => [
                'id' => $punch->getKey(), 'unit_id' => $punch->unit_id, 'employee_id' => $punch->employee_id,
                'punched_at' => $punch->punched_at->toIso8601String(), 'source' => $punch->source, 'note' => $punch->note,
                'voided' => $punch->voided_at !== null, 'void_reason' => $punch->void_reason,
            ]),
            'days' => $this->rows(AttendanceDay::class, $organization, $organizationIds, fn (AttendanceDay $day) => [
                'id' => $day->getKey(), 'unit_id' => $day->unit_id, 'employee_id' => $day->employee_id,
                'work_date' => $day->work_date->toDateString(), 'status' => $day->status->value,
                'first_in_at' => $day->first_in_at?->toIso8601String(), 'last_out_at' => $day->last_out_at?->toIso8601String(),
                'worked_minutes' => $day->worked_minutes, 'late_minutes' => $day->late_minutes, 'overtime_minutes' => $day->overtime_minutes,
            ]),
            'corrections' => $this->rows(Correction::class, $organization, $organizationIds, fn (Correction $correction) => [
                'id' => $correction->getKey(), 'employee_id' => $correction->employee_id, 'work_date' => $correction->work_date->toDateString(),
                'in_at' => $correction->in_at?->toIso8601String(), 'out_at' => $correction->out_at?->toIso8601String(),
                'reason' => $correction->reason, 'status' => $correction->status, 'decision_note' => $correction->decision_note,
            ]),
        ];
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @param  list<string>  $organizationIds
     * @param  callable(T): array<string, scalar|null>  $row
     * @return iterable<array<string, scalar|null>>
     */
    private function rows(string $model, Organization $organization, array $organizationIds, callable $row): iterable
    {
        foreach (array_chunk($organizationIds, 500) as $chunk) {
            foreach ($model::inTenantOf($organization)->withoutGlobalScope(OrganizationScope::class)->whereIn('organization_id', $chunk)->orderBy('id')->lazy(500) as $record) {
                yield $row($record);
            }
        }
    }
}
