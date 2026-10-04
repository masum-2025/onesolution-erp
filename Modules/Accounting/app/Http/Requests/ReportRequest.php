<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Report filters: a date (trial balance, balance sheet, aging) or a date
 * range (ledger, profit and loss, statement), an account for the ledger, a
 * side (sales or purchases) and party for receivables reports, and an
 * optional cost centre for the ledger reports (its units included).
 */
class ReportRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $report = $this->route('report');
        $range = in_array($report, ['ledger', 'profit-loss', 'statement'], true);
        $parties = in_array($report, ['aging', 'statement'], true);

        return [
            'as_of' => [$range ? 'prohibited' : 'nullable', 'date_format:Y-m-d'],
            'from' => [$range ? 'required' : 'prohibited', 'date_format:Y-m-d'],
            'to' => [$range ? 'required' : 'prohibited', 'date_format:Y-m-d', 'after_or_equal:from'],
            'account_id' => [$report === 'ledger' ? 'required' : 'prohibited', 'string', 'size:26'],
            'side' => [$parties ? 'required' : 'prohibited', 'string', 'in:sales,purchases'],
            'party_id' => [$report === 'statement' ? 'required' : 'prohibited', 'string', 'size:26'],
            'cost_centre_id' => [$parties ? 'prohibited' : 'nullable', 'string', 'size:26'],
        ];
    }
}
