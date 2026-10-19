<?php

namespace Modules\Leave\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** HR changes a leave balance by hand: half days (+ or −, not 0) in a leave year, and why. */
class AdjustBalanceRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'string', 'max:26'],
            'type_id' => ['required', 'string', 'max:26'],
            'leave_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'halves' => ['required', 'integer', 'not_in:0', 'min:-730', 'max:730'],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
