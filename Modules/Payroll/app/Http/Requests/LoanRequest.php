<?php

namespace Modules\Payroll\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Payroll\Models\Loan;

/**
 * Asking for a loan or advance, deciding it (the version seen; a reason to
 * reject), or holding a month's instalment back (the month, a reason).
 */
class LoanRequest extends StrictFormRequest
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
                'note' => [$step === 'reject' ? 'required' : 'nullable', 'string', 'min:5', 'max:500'],
            ];
        }
        if (str_ends_with($this->path(), '/skips')) {
            return [
                'period' => ['required', 'date_format:Y-m'],
                'reason' => ['required', 'string', 'min:5', 'max:300'],
            ];
        }

        return [
            'employee_id' => ['required', 'string', 'max:26'],
            'kind' => ['required', 'in:'.implode(',', Loan::KINDS)],
            'principal_minor' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'installments' => ['required', 'integer', 'min:1', 'max:600'],
            'start_period' => ['required', 'date_format:Y-m'],
            'paid_out_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:300'],
        ];
    }
}
