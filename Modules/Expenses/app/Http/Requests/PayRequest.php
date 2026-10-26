<?php

namespace Modules\Expenses\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Expenses\Models\Claim;

/** Paying a claim back: cash or bank now, or with a month's salary (YYYY-MM); the version seen. */
class PayRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'string', 'in:'.implode(',', Claim::PAY_METHODS)],
            'period' => ['required_if:method,payroll', 'nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ];
    }
}
