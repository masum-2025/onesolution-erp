<?php

namespace Modules\OnlineOrders\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\OnlineOrders\Models\OrderPayment;

/**
 * SC-7: a parcel the courier brought back is in the warehouse: the order's
 * version seen, an optional note (what came back, its state) and where
 * money paid ahead goes back (asked when there is any).
 */
class ReceiveBackRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:200'],
            'refund_method' => ['nullable', 'string', 'in:'.implode(',', OrderPayment::METHODS)],
        ];
    }
}
