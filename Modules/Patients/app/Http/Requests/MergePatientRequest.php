<?php

namespace Modules\Patients\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Asking to fold this patient into another (the record that stays), with a reason.
 */
class MergePatientRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'into_id' => ['required', 'string', 'size:26'],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ];
    }
}
