<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A step of the opening balances (submit, withdraw, approve, reject): the
 * version the person saw, and for reject a reason.
 */
class OpeningStepRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'reason' => [$this->route('step') === 'reject' ? 'required' : 'prohibited', 'string', 'min:5', 'max:300'],
        ];
    }
}
