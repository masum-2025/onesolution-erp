<?php

namespace Modules\Diagnostics\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Diagnostics\Models\Payout;

/**
 * Paying a referring doctor (amount in minor units, how, when, an op id for
 * retries) or taking an order's commission back (a reason), by route name.
 */
class CommissionRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'diagnostics.payouts.store' => [
                'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
                'method' => ['required', 'in:'.implode(',', Payout::METHODS)],
                'paid_on' => ['required', 'date_format:Y-m-d'],
                'note' => ['nullable', 'string', 'max:300'],
                'op_id' => ['nullable', 'string', 'max:64'],
            ],
            'diagnostics.commissions.reverse' => ['reason' => ['required', 'string', 'min:3', 'max:300']],
            default => [],
        };
    }
}
