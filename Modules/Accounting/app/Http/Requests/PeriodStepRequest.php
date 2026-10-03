<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Close or reopen a period. Reopening books that were closed needs a reason
 * (it is in the audit log).
 */
class PeriodStepRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => [$this->route('step') === 'reopen' ? 'required' : 'nullable', 'string', 'min:5', 'max:500'],
        ];
    }
}
