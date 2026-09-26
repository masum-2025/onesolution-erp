<?php

namespace App\Platform\SupportAccess\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Rejecting a request or ending access: the reason goes to the audit log.
 */
class SupportReasonRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:500']];
    }
}
