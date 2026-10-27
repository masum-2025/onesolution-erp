<?php

namespace Modules\Expenses\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** An advance asked for: how much (minor units), what for, by when it is to be settled; the same op id is the same advance. */
class AdvanceRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'purpose' => ['required', 'string', 'min:3', 'max:300'],
            'settle_by' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'op_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
