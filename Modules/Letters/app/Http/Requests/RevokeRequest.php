<?php

namespace Modules\Letters\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** A letter revoked: why (kept with it and in the audit log). */
class RevokeRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:500']];
    }
}
