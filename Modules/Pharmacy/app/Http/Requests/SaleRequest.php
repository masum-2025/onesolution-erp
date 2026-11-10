<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A sale at the pharmacy counter: medicines by piece, strip or box, payments,
 * the customer, and the prescription (needed for controlled medicines).
 */
class SaleRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'op_id' => ['required', 'string', 'max:64'],
            'register_id' => ['required', 'string', 'size:26'],
            'session_id' => ['nullable', 'string', 'size:26'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.medicine_id' => ['required', 'string', 'size:26'],
            'lines.*.pack' => ['required', 'in:piece,strip,box'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.discount_minor' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'payments' => ['required', 'array', 'min:1', 'max:5'],
            'payments.*.method' => ['required', 'in:cash,card,mobile'],
            'payments.*.amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'payments.*.reference' => ['nullable', 'string', 'max:60'],
            'prescription' => ['nullable', 'array:patient_name,prescriber_name,prescriber_bmdc,ref'],
            'prescription.patient_name' => ['nullable', 'string', 'max:120'],
            'prescription.prescriber_name' => ['nullable', 'string', 'max:120'],
            'prescription.prescriber_bmdc' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z]{0,3}-?\d{1,10}$/'],
            'prescription.ref' => ['nullable', 'string', 'max:60'],
        ];
    }
}
