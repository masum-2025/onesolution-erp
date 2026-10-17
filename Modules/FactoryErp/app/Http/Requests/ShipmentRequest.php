<?php

namespace Modules\FactoryErp\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Making or changing a draft shipment: the order (fixed once made), ship
 * date, mode, carrier, transport reference (B/L, AWB), destination, the
 * store goods leave from, the exchange rate (company currency per one of
 * the buyer's; needed when they differ), whether it is the order's last,
 * a note, and the packing list (runs of cartons).
 */
class ShipmentRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'order_id' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'],
            'ship_on' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'mode' => [$creating ? 'required' : 'sometimes', 'in:sea,air,road'],
            'carrier' => ['sometimes', 'nullable', 'string', 'max:100'],
            'transport_ref' => ['sometimes', 'nullable', 'string', 'max:60'],
            'destination' => ['sometimes', 'nullable', 'string', 'max:120'],
            'warehouse_id' => [$creating ? 'required' : 'sometimes', 'string', 'size:26'],
            'exchange_rate' => ['sometimes', 'nullable', 'string', 'max:20'],
            'is_last' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'cartons' => ['sometimes', 'array', 'max:500'],
            'cartons.*.from_no' => ['required', 'integer', 'min:1', 'max:1000000'],
            'cartons.*.to_no' => ['required', 'integer', 'min:1', 'max:1000000'],
            'cartons.*.size' => ['required', 'string', 'max:20'],
            'cartons.*.color' => ['required', 'string', 'max:40'],
            'cartons.*.pieces_each' => ['required', 'integer', 'min:1', 'max:10000'],
            'cartons.*.net_grams_each' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'cartons.*.gross_grams_each' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'cartons.*.length_mm' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'cartons.*.width_mm' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'cartons.*.height_mm' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'op_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
