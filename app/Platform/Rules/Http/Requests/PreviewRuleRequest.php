<?php

namespace App\Platform\Rules\Http\Requests;

use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

class PreviewRuleRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(RuleMode::class)],
            'value' => ['present'],
            'country_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}$/'],
        ];
    }
}
