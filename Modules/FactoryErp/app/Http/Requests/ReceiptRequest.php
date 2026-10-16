<?php

namespace Modules\FactoryErp\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Finished pieces of an order into a store: the store, the day (today when
 * left out, never later), pieces per size and colour, whether it is the
 * last (it takes all the work in progress left), a note.
 */
class ReceiptRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'string', 'size:26'],
            'received_on' => ['sometimes', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'min:1', 'max:1200'],
            'lines.*.size' => ['required', 'string', 'max:20'],
            'lines.*.color' => ['required', 'string', 'max:40'],
            'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'is_last' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
            'op_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
