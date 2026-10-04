<?php

namespace Modules\Payroll\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Opening a month's payroll (period "2026-10"), a step of a run (the
 * version seen; a reason to reject; the day it was paid), or a one-off
 * adjustment for an employee.
 */
class RunRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $step = $this->route('step');
        if ($step !== null) {
            return [
                'base_version' => ['required', 'integer', 'min:1'],
                'reason' => [$step === 'reject' ? 'required' : 'prohibited', 'string', 'min:5', 'max:500'],
                'paid_on' => [$step === 'pay' ? 'required' : 'prohibited', 'date_format:Y-m-d'],
            ];
        }
        if (str_ends_with($this->path(), '/adjustments')) {
            return [
                'employee_id' => ['required', 'string', 'max:26'],
                'kind' => ['required', 'in:earning,deduction'],
                'label' => ['required', 'string', 'min:2', 'max:120'],
                'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999'],
                'taxable' => ['sometimes', 'boolean'],
            ];
        }

        return ['period' => ['required', 'date_format:Y-m']];
    }
}
