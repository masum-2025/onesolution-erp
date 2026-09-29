<?php

namespace App\Platform\Offline\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * An admin decision with an optional reason (audited).
 */
class OfflineReasonRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }
}
