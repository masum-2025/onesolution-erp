<?php

namespace Modules\Attendance\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** Put employees on a shift (null: no fixed hours) from a day. */
class RosterRequest extends StrictFormRequest
{
    /** Most employees placed at once. */
    public const MAX_EMPLOYEES = 200;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_EMPLOYEES],
            'employee_ids.*' => ['string', 'max:26', 'distinct'],
            'shift_id' => ['present', 'nullable', 'string', 'max:26'],
            'from' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
