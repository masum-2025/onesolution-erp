<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Stock going back to a supplier: the supplier, why, and the batches (in pieces) from one warehouse.
 */
class SupplierReturnRequest extends StrictFormRequest
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
            'reason' => ['required', 'string', 'max:300'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.medicine_id' => ['required', 'string', 'size:26'],
            'lines.*.batch_number' => ['required', 'string', 'max:60'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}
