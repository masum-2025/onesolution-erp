<?php

namespace Modules\Attendance\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A punch written by HR: whose, when (local time at the company), why. And
 * for voiding one, the reason. op_id makes a repeat harmless.
 */
class PunchRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->route('punch') !== null) {
            return ['reason' => ['required', 'string', 'min:5', 'max:300']];
        }

        return [
            'employee_id' => ['required', 'string', 'max:26'],
            'at' => ['required', 'date_format:Y-m-d\TH:i'],
            'note' => ['required', 'string', 'min:3', 'max:300'],
            'op_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
