<?php

namespace Modules\Payroll\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Payroll\Models\PaymentDetail;

/**
 * An employee's salary from a day (structure, basic in minor units, why),
 * or where they are paid (method; provider, account name and number unless
 * cash).
 */
class EmployeePayRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->isMethod('put')) {
            return [
                'method' => ['required', 'in:'.implode(',', PaymentDetail::METHODS)],
                'provider' => ['nullable', 'string', 'max:100'],
                'account_name' => ['nullable', 'string', 'max:150'],
                'account_number' => ['nullable', 'string', 'regex:/^[A-Za-z0-9 \-]{4,40}$/'],
                'branch' => ['nullable', 'string', 'max:150'],
            ];
        }

        return [
            'structure_id' => ['required', 'string', 'max:26'],
            'basic_minor' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:300'],
        ];
    }
}
