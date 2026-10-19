<?php

namespace Modules\Leave\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Paying unused leave out: who, which kind, which leave year, how many half
 * days and why; or deciding it (approve / reject, an optional note) with
 * the version seen.
 */
class EncashmentRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->route('step') !== null) {
            return [
                'base_version' => ['required', 'integer', 'min:1'],
                'note' => ['nullable', 'string', 'max:500'],
            ];
        }

        return [
            'employee_id' => ['required', 'string', 'max:26'],
            'type_id' => ['required', 'string', 'max:26'],
            'leave_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'halves' => ['required', 'integer', 'min:1', 'max:730'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
