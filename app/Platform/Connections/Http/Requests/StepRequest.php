<?php

namespace App\Platform\Connections\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Pausing, resuming or ending a connection; ending needs a reason (audited, told to the other side).
 */
class StepRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'reason' => [$this->route('step') === 'end' ? 'required' : 'nullable', 'string', 'min:3', 'max:500'],
        ];
    }
}
