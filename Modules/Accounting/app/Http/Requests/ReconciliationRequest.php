<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A statement's last day and its balance there (minor units, below zero
 * for an overdraft); the first time also its balance before its first line.
 */
class ReconciliationRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $money = ['integer', 'between:-999999999999999,999999999999999'];

        return [
            'statement_date' => ['required', 'date_format:Y-m-d'],
            'statement_balance_minor' => ['required', ...$money],
            'opening_balance_minor' => ['nullable', ...$money],
        ];
    }
}
