<?php

namespace App\Platform\Portal\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A decision about a portal link, with an optional reason (audited).
 */
class PortalDecisionRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }
}
