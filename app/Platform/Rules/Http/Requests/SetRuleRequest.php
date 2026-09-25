<?php

namespace App\Platform\Rules\Http\Requests;

use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

class SetRuleRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(RuleMode::class)],
            // Shape is checked against the rule's JSON Schema by RuleService.
            'value' => ['present'],
            'country_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            // Changes can be scheduled, never backdated from the API.
            'effective_from' => ['sometimes', 'nullable', 'date', 'after_or_equal:now'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
