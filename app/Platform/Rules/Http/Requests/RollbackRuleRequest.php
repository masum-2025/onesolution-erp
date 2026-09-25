<?php

namespace App\Platform\Rules\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class RollbackRuleRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
