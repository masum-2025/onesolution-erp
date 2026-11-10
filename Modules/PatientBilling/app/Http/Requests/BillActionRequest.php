<?php

namespace Modules\PatientBilling\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * What each step on a bill takes, by route name: adding a service (and how
 * many), voiding a line, choosing the panel, a discount with its reason,
 * finalizing, voiding the bill with a reason. Steps that change the whole
 * bill carry its version.
 */
class BillActionRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'patient_billing.lines.add' => [
                'service_id' => ['required', 'string', 'size:26'],
                'quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            ],
            'patient_billing.bills.panel' => [
                'panel_id' => ['present', 'nullable', 'string', 'size:26'],
                'base_version' => ['required', 'integer', 'min:1'],
            ],
            'patient_billing.bills.discount' => [
                'amount_minor' => ['required', 'integer', 'min:0', 'max:100000000000000'],
                'reason' => ['required', 'string', 'min:3', 'max:300'],
            ],
            'patient_billing.bills.finalize' => [
                'base_version' => ['required', 'integer', 'min:1'],
            ],
            'patient_billing.bills.void' => [
                'reason' => ['required', 'string', 'min:3', 'max:300'],
                'base_version' => ['required', 'integer', 'min:1'],
            ],
            default => [],
        };
    }
}
