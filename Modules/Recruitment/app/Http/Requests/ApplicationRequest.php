<?php

namespace Modules\Recruitment\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** An application moved to another stage and/or rated, with the version seen and a note for the move. */
class ApplicationRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'stage' => ['sometimes', 'string', 'max:30'],
            'rating' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
