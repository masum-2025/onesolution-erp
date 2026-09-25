<?php

namespace App\Platform\Modules\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class PurgeModuleRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Must equal the module key, typed by the user.
            'confirm_text' => ['required', 'string', 'max:50'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
