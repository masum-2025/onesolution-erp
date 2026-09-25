<?php

namespace App\Platform\Modules\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class GrantConsentRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Version of the data-processing terms the admin agreed to.
            'terms_version' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
