<?php

namespace Modules\Compliance\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;
use Modules\Compliance\Models\PaymentAdvice;

/**
 * Telling the provider about a payment: how much (minor units of the
 * invoice's currency), when, how, and the bank or cheque reference.
 */
class AdviceRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999999'],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'method' => ['required', Rule::in(PaymentAdvice::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'op_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.:-]+$/'],
        ];
    }
}
