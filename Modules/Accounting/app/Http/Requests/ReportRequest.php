<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Report filters: a date (trial balance, balance sheet) or a date range
 * (ledger, profit and loss), an account for the ledger, and an optional
 * cost centre (a branch or department; its units below are included).
 */
class ReportRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $report = $this->route('report');
        $range = in_array($report, ['ledger', 'profit-loss'], true);

        return [
            'as_of' => [$range ? 'prohibited' : 'nullable', 'date_format:Y-m-d'],
            'from' => [$range ? 'required' : 'prohibited', 'date_format:Y-m-d'],
            'to' => [$range ? 'required' : 'prohibited', 'date_format:Y-m-d', 'after_or_equal:from'],
            'account_id' => [$report === 'ledger' ? 'required' : 'prohibited', 'string', 'size:26'],
            'cost_centre_id' => ['nullable', 'string', 'size:26'],
        ];
    }
}
