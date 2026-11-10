<?php

namespace Modules\PatientBilling\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Deciding someone else's discount request (a note is needed to reject), or
 * opening, closing and reviewing a cash day, by route name.
 */
class DecisionRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'patient_billing.discounts.decide' => [
                'approve' => ['required', 'boolean'],
                'note' => ['nullable', 'required_if:approve,false', 'string', 'max:300'],
            ],
            'patient_billing.days.open' => [
                'opening_float_minor' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            ],
            'patient_billing.days.close' => [
                'counted_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
                'base_version' => ['required', 'integer', 'min:1'],
            ],
            'patient_billing.days.review' => [
                'note' => ['required', 'string', 'min:3', 'max:300'],
            ],
            default => [],
        };
    }
}
