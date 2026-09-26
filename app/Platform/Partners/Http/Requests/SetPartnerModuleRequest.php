<?php

namespace App\Platform\Partners\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class SetPartnerModuleRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // inherit = remove the partner's setting; each client decides again.
            'state' => ['required', 'string', 'in:enabled,disabled,inherit'],
            'lock' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
