<?php

namespace App\Platform\Rules\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Approving a pending rule change; a note is optional.
 */
class ReviewRuleRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
