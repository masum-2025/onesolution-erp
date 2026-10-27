<?php

namespace Modules\Recruitment\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** Hiring a candidate into HRM: the version seen, the first day, the kind of employment and a manager (HRM checks them with its rules). */
class HireRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'joined_on' => ['required', 'date_format:Y-m-d'],
            'employment_type' => ['nullable', 'string', 'max:30'],
            'manager_id' => ['nullable', 'string', 'size:26'],
        ];
    }
}
