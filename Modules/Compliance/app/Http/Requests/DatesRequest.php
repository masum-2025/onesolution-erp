<?php

namespace Modules\Compliance\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** The official last audit and expiry days of a register line, always with a reason. */
class DatesRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'last_audit_on' => ['nullable', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
