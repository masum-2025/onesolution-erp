<?php

namespace Modules\FactoryErp\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A style's whole bill of materials: each line an Inventory item, how much
 * per piece or dozen (thousandths of the item's unit), wastage (basis
 * points; empty takes the rule's), and maybe one size and/or colour.
 */
class BomRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'lines' => ['present', 'array', 'max:100'],
            'lines.*.item_id' => ['required', 'string', 'size:26'],
            'lines.*.consumption_milli' => ['required', 'integer', 'min:1', 'max:100000000'],
            'lines.*.wastage_bp' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'lines.*.size' => ['nullable', 'string', 'max:20'],
            'lines.*.color' => ['nullable', 'string', 'max:40'],
            'lines.*.note' => ['nullable', 'string', 'max:200'],
        ];
    }
}
