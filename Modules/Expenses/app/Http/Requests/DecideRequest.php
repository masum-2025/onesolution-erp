<?php

namespace Modules\Expenses\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** A decision on a claim's step: the version seen, and a note (required when rejecting). */
class DecideRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'note' => [$this->route('step') === 'reject' ? 'required' : 'nullable', 'string', 'max:500'],
        ];
    }
}
