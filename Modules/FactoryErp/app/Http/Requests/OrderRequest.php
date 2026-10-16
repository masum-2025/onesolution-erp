<?php

namespace Modules\FactoryErp\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Making or changing a buyer's order: the style (fixed once made), the
 * buyer's PO number, ship date, currency and price per piece (minor
 * units), pieces per size and colour, a note and own fields.
 */
class OrderRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'style_id' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'],
            'po_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'ship_on' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'unit_price_minor' => ['sometimes', 'integer', 'min:0', 'max:100000000000'],
            'lines' => ['sometimes', 'array', 'max:1200'],
            'lines.*.size' => ['required', 'string', 'max:20'],
            'lines.*.color' => ['required', 'string', 'max:40'],
            'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:10000000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'extra' => ['sometimes', 'array'],
            'op_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
