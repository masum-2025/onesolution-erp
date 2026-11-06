<?php

namespace App\Platform\Marketing\Http\Requests;

use App\Platform\Marketing\Models\Lead;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

/** Following up a website message: where it stands and the staff's own note. */
class UpdateLeadRequest extends StrictFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'status' => ['sometimes', 'required', Rule::in(Lead::STATUSES)],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
