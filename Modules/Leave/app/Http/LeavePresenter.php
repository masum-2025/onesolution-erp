<?php

namespace Modules\Leave\Http;

use Modules\Hrm\Directory\EmployeeRecord;
use Modules\Leave\Models\LeaveRequest;
use Modules\Leave\Models\LeaveType;

/** Leave records as the API shows them. Amounts are half days (2 = one day). */
class LeavePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function type(LeaveType $type): array
    {
        return [
            'id' => $type->getKey(), 'code' => $type->code, 'name' => $type->textIn('name'), 'names' => $type->texts('name'),
            'paid' => $type->paid, 'accrual' => $type->accrual, 'allowance_halves' => $type->allowance_halves,
            'worked_days_per_day' => $type->worked_days_per_day, 'carry_max_halves' => $type->carry_max_halves,
            'half_day' => $type->half_day, 'counts_days_off' => $type->counts_days_off, 'document_after_days' => $type->document_after_days,
            'gender' => $type->gender, 'encashable' => $type->encashable, 'is_active' => $type->is_active, 'sort_order' => $type->sort_order,
            'version' => $type->version,
        ];
    }

    /**
     * @param  array{type: LeaveType, given: int, taken: int, balance: int, waiting: int, available: int}  $row
     * @return array<string, mixed>
     */
    public function balance(array $row): array
    {
        return [
            'type_id' => $row['type']->getKey(), 'code' => $row['type']->code, 'name' => $row['type']->textIn('name'), 'paid' => $row['type']->paid,
            'half_day' => $row['type']->half_day, 'given_halves' => $row['given'], 'taken_halves' => $row['taken'],
            'balance_halves' => $row['balance'], 'waiting_halves' => $row['waiting'], 'available_halves' => $row['available'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function request(LeaveRequest $request, ?EmployeeRecord $employee = null, ?LeaveType $type = null, ?bool $canDecide = null, ?bool $mine = null): array
    {
        return [
            'id' => $request->getKey(), 'employee_id' => $request->employee_id,
            ...($employee === null ? [] : ['employee_name' => $employee->name, 'employee_code' => $employee->code]),
            'type_id' => $request->type_id, ...($type === null ? [] : ['type_name' => $type->textIn('name'), 'type_code' => $type->code]),
            'leave_year' => $request->leave_year, 'from_date' => $request->from_date->toDateString(), 'to_date' => $request->to_date->toDateString(),
            'from_half' => $request->from_half, 'to_half' => $request->to_half, 'halves' => $request->halves, 'unpaid_halves' => $request->unpaid_halves,
            'reason' => $request->reason, 'status' => $request->status, 'steps_needed' => $request->steps_needed, 'steps_done' => $request->steps_done,
            'decision_note' => $request->decision_note, 'decided_at' => $request->decided_at?->toIso8601String(),
            'requested_at' => $request->created_at?->toIso8601String(), 'version' => $request->version,
            ...($canDecide === null ? [] : ['can_decide' => $canDecide]),
            ...($mine === null ? [] : ['mine' => $mine]),
        ];
    }
}
