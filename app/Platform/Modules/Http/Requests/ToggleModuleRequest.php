<?php

namespace App\Platform\Modules\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class ToggleModuleRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            // Children cannot change the module while locked.
            'lock' => ['sometimes', 'boolean'],
            // Required (true) to also disable modules that depend on this one.
            'confirm' => ['sometimes', 'boolean'],
        ];
    }
}
