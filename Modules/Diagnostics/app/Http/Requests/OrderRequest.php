<?php

namespace Modules\Diagnostics\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Ordering tests for a patient (and the referring doctor, if any); one op id makes one order.
 */
class OrderRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'string', 'size:26'],
            'test_ids' => ['required', 'array', 'min:1', 'max:40'],
            'test_ids.*' => ['required', 'string', 'size:26', 'distinct'],
            'referrer_id' => ['nullable', 'string', 'size:26'],
            'notes' => ['nullable', 'string', 'max:300'],
            'op_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
