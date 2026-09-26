<?php

namespace App\Platform\Branding\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class ClientBrandRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // null = use the partner's product name / color.
            'display_name' => ['sometimes', 'nullable', 'string', 'min:2', 'max:60', 'regex:/^[^<>]+$/u'],
            'primary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }
}
