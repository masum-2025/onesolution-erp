<?php

namespace Modules\Payroll\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Opening a final settlement (the employee), a step of it (the version
 * seen; a reason to reject; the day it was paid), or a line by hand.
 */
class SettlementRequest extends StrictFormRequest
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
        if (str_ends_with($this->path(), '/lines')) {
            return [
                'kind' => ['required', 'in:earning,deduction'],
                'label' => ['required', 'string', 'min:2', 'max:120'],
                'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999'],
                'taxable' => ['sometimes', 'boolean'],
            ];
        }

        return ['employee_id' => ['required', 'string', 'max:26']];
    }
}
