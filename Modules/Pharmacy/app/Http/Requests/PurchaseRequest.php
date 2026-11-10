<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Buying medicines: the supplier, the warehouse, and every line with its pack,
 * quantity, cost of one pack, batch number and expiry (both required).
 */
class PurchaseRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'op_id' => ['required', 'string', 'max:64'],
            'warehouse_id' => ['required', 'string', 'size:26'],
            'supplier' => ['required', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:80'],
            'purchased_on' => ['required', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.medicine_id' => ['required', 'string', 'size:26'],
            'lines.*.pack' => ['required', 'in:piece,strip,box'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.pack_cost_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'lines.*.batch_number' => ['required', 'string', 'max:60'],
            'lines.*.expires_on' => ['required', 'date_format:Y-m-d', 'after:purchased_on'],
        ];
    }
}
