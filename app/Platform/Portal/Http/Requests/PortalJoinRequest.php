<?php

namespace App\Platform\Portal\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * An invitation's link token or short code.
 */
class PortalJoinRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\s-]+$/']];
    }
}
