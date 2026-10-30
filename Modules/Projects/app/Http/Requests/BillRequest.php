<?php

namespace Modules\Projects\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A quote or an invoice for a project: its lines (description, quantity in
 * thousandths, unit price in minor units, a discount on quotes, a VAT code
 * from Accounting) and, for a quote, estimate or quotation; for an invoice,
 * its issue day.
 */
class BillRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $quote = $this->route()?->getName() === 'projects.quote';

        return [
            'kind' => [$quote ? 'required' : 'prohibited', 'in:estimate,quotation'],
            'issue_date' => [$quote ? 'prohibited' : 'required', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*' => [$quote ? 'array:description,quantity_milli,unit_price_minor,discount_minor,tax_code_id' : 'array:description,quantity_milli,unit_price_minor,tax_code_id'],
            'lines.*.description' => ['required', 'string', 'min:1', 'max:255'],
            'lines.*.quantity_milli' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'lines.*.unit_price_minor' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'lines.*.discount_minor' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'lines.*.tax_code_id' => ['nullable', 'string', 'size:26'],
        ];
    }
}
