<?php

namespace Modules\Expenses\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A claim's draft: a title and its lines (a day, a kind, what for, an amount in
 * minor units or kilometres in tenths); an op id when made (a phone may send
 * it twice); the version seen when changing.
 */
class ClaimRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'op_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:150'],
            'lines' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:50'],
            'lines.*.id' => ['nullable', 'string', 'size:26'],
            'lines.*.spent_on' => ['required', 'date_format:Y-m-d'],
            'lines.*.category_id' => ['required', 'string', 'size:26'],
            'lines.*.description' => ['required', 'string', 'min:2', 'max:300'],
            'lines.*.amount_minor' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'lines.*.distance_tenths' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'lines.*.per_diem_code' => ['nullable', 'string', 'max:20'],
            'lines.*.days' => ['nullable', 'integer', 'min:1', 'max:60'],
            // An advance the claim settles (the employee's own, paid, something left).
            'advance_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ];
    }
}
