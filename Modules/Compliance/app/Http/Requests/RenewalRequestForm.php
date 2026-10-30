<?php

namespace Modules\Compliance\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** A renewal request for a register line: by when it is wanted, and a note to the provider. */
class RenewalRequestForm extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'wanted_by' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
