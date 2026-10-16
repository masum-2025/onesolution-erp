<?php

namespace Modules\FactoryErp\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * One lay cut for an order: the day (today when left out, never later),
 * pieces per size and colour, a note.
 */
class CuttingRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cut_on' => ['sometimes', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'min:1', 'max:1200'],
            'lines.*.size' => ['required', 'string', 'max:20'],
            'lines.*.color' => ['required', 'string', 'max:40'],
            'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
            'op_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
