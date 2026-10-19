<?php

namespace Modules\Leave\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Modules\Leave\Models\LeaveApproval;
use Modules\Leave\Models\LeaveEncashment;
use Modules\Leave\Models\LeaveEntry;
use Modules\Leave\Models\LeaveRequest;
use Modules\Leave\Models\LeaveType;

/**
 * Leave in the client's data export: types, requests with their approval
 * steps, and the balance ledger (half days). Runs without a tenant context,
 * reading the client's own database for the organizations given.
 */
class LeaveExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'leave';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        return [
            'types' => $this->rows(LeaveType::class, $organization, $organizationIds, fn (LeaveType $type) => [
                'id' => $type->getKey(), 'organization_id' => $type->organization_id, 'code' => $type->code,
                'name' => json_encode($type->texts('name'), JSON_UNESCAPED_UNICODE), 'paid' => $type->paid, 'accrual' => $type->accrual,
                'allowance_halves' => $type->allowance_halves, 'worked_days_per_day' => $type->worked_days_per_day, 'carry_max_halves' => $type->carry_max_halves,
                'half_day' => $type->half_day, 'counts_days_off' => $type->counts_days_off, 'gender' => $type->gender, 'is_active' => $type->is_active,
            ]),
            'requests' => $this->rows(LeaveRequest::class, $organization, $organizationIds, fn (LeaveRequest $request) => [
                'id' => $request->getKey(), 'unit_id' => $request->unit_id, 'employee_id' => $request->employee_id, 'type_id' => $request->type_id,
                'leave_year' => $request->leave_year, 'from_date' => $request->from_date->toDateString(), 'to_date' => $request->to_date->toDateString(),
                'from_half' => $request->from_half, 'to_half' => $request->to_half, 'halves' => $request->halves, 'unpaid_halves' => $request->unpaid_halves,
                'reason' => $request->reason, 'status' => $request->status, 'decision_note' => $request->decision_note,
            ]),
            'approvals' => $this->rows(LeaveApproval::class, $organization, $organizationIds, fn (LeaveApproval $approval) => [
                'request_id' => $approval->request_id, 'step' => $approval->step, 'user_id' => $approval->user_id,
                'decision' => $approval->decision, 'note' => $approval->note, 'at' => $approval->created_at?->toIso8601String(),
            ]),
            'encashments' => $this->rows(LeaveEncashment::class, $organization, $organizationIds, fn (LeaveEncashment $item) => [
                'id' => $item->getKey(), 'employee_id' => $item->employee_id, 'type_id' => $item->type_id, 'leave_year' => $item->leave_year,
                'halves' => $item->halves, 'reason' => $item->reason, 'status' => $item->status, 'pay_on' => $item->pay_on?->toDateString(),
            ]),
            'ledger' => $this->rows(LeaveEntry::class, $organization, $organizationIds, fn (LeaveEntry $entry) => [
                'employee_id' => $entry->employee_id, 'type_id' => $entry->type_id, 'leave_year' => $entry->leave_year, 'kind' => $entry->kind,
                'halves' => $entry->halves, 'source' => $entry->source, 'note' => $entry->note, 'at' => $entry->created_at?->toIso8601String(),
            ]),
        ];
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  list<string>  $organizationIds
     * @return iterable<array<string, mixed>>
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
