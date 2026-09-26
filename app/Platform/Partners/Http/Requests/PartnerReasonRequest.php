<?php

namespace App\Platform\Partners\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A change that only needs a reason for the audit log (e.g. removing a domain).
 */
class PartnerReasonRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:500']];
    }
}
