<?php

namespace Modules\OnlineOrders\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\OnlineOrders\Models\CourierPayout;

/**
 * SC-6: a payout from a courier: the payments it settles, what arrived and
 * where, the courier's charge, the day, and a reason when it does not add
 * up (Couriers payouts check that). Sent again with the same op id, it
 * changes nothing.
 */
class CourierPayoutRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'op_id' => ['required', 'string', 'min:8', 'max:64'],
            'payment_ids' => ['required', 'array', 'min:1', 'max:500'],
            'payment_ids.*' => ['required', 'string', 'size:26'],
            'method' => ['required', 'string', 'in:'.implode(',', CourierPayout::METHODS)],
            'received_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'fee_minor' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:80'],
            'reason' => ['nullable', 'string', 'max:300'],
        ];
    }
}
