<?php

namespace App\Platform\Rules\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class ResetRuleRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // value = this level's own value / lock; constraint = bounds for children; omitted = both.
            'slot' => ['sometimes', 'string', 'in:value,constraint'],
            'country_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
