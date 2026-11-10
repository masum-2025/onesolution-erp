<?php

namespace Modules\PatientBilling\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\PatientBilling\Models\Payment;

/**
 * Money in (a payment on a bill, or an advance for a patient) or out (a
 * refund on a bill, with a reason), always with an op id so a second tap or
 * a retry never takes or gives the money twice. Amounts in minor units.
 */
class MoneyRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $refund = $this->route()?->getName() === 'patient_billing.bills.refund';

        return [
            'op_id' => ['required', 'string', 'max:64'],
            'kind' => $refund ? ['prohibited'] : ['required', 'in:payment,advance'],
            'bill_id' => $refund ? ['prohibited'] : ['required_if:kind,payment', 'prohibited_if:kind,advance', 'nullable', 'string', 'size:26'],
            'patient_id' => $refund ? ['prohibited'] : ['required_if:kind,advance', 'prohibited_if:kind,payment', 'nullable', 'string', 'size:26'],
            'method' => ['required', 'in:'.implode(',', Payment::METHODS)],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000000'],
            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => $refund ? ['required', 'string', 'min:3', 'max:300'] : ['prohibited'],
        ];
    }
}
