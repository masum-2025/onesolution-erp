<?php

namespace Modules\PatientBilling\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Opening a bill for a patient, optionally for a corporate or panel client; one op id makes one bill.
 */
class BillRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'string', 'size:26'],
            'panel_id' => ['nullable', 'string', 'size:26'],
            'op_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
