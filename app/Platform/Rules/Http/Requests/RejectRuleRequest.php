<?php

namespace App\Platform\Rules\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Rejecting a pending rule change needs a reason the requester can read.
 */
class RejectRuleRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
