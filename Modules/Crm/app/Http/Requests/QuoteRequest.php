<?php

namespace Modules\Crm\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Crm\Models\Quote;
use Modules\Crm\Services\Quotes;

/**
 * An estimate or quotation made or changed (the version seen), or a step of it (a reason to decline; whether to make an invoice on accepting).
 */
class QuoteRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $step = $this->route('step');
        if ($step !== null) {
            return [
                'base_version' => ['required', 'integer', 'min:1'],
                'reason' => [$step === 'decline' ? 'required' : 'prohibited', 'string', 'min:3', 'max:300'],
                'invoice' => [$step === 'accept' ? 'sometimes' : 'prohibited', 'boolean'],
            ];
        }
        $creating = $this->isMethod('post');

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'unit_id' => [$creating ? 'sometimes' : 'prohibited', 'nullable', 'string', 'max:26'],
            'kind' => [$creating ? 'required' : 'prohibited', 'in:'.implode(',', Quote::KINDS)],
            'contact_id' => [$creating ? 'required' : 'sometimes', 'string', 'size:26'],
            'deal_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'issue_date' => ['sometimes', 'date_format:Y-m-d'],
            'valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'extra' => ['sometimes', 'array', 'max:40'],
            'lines' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:'.Quotes::MAX_LINES],
            'lines.*' => ['array:item_id,description,quantity_milli,unit,unit_price_minor,discount_minor,tax_code_id,extra'],
            'lines.*.item_id' => ['nullable', 'string', 'size:26'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity_milli' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
            'lines.*.unit_price_minor' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'lines.*.discount_minor' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'lines.*.tax_code_id' => ['nullable', 'string', 'size:26'],
            'lines.*.extra' => ['nullable', 'array', 'max:40'],
        ];
    }
}
