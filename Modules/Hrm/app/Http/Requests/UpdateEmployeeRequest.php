<?php

namespace Modules\Hrm\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Editing an employee's details. Unit, position and status change only
 * through employment steps (transfer, promote, notice, exit).
 */
class UpdateEmployeeRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = array_map(fn (array $rules) => ['sometimes', ...$rules], HireEmployeeRequest::detailRules());

        return [
            'base_version' => ['required', 'integer', 'min:1'],
            ...$rules,
        ];
    }
}
