<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Accounting\Enums\DocumentType;

/**
 * An invoice, credit note, bill or vendor credit as written: party, dates,
 * lines (quantity as text with up to 3 decimals, unit price in minor units,
 * account, cost centre). "submit" sends it straight away.
 */
class DocumentRequest extends StrictFormRequest
{
    /** Most lines one document may have. */
    public const MAX_LINES = 200;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'type' => [$creating ? 'required' : 'prohibited', 'string', 'in:'.implode(',', array_column(DocumentType::cases(), 'value'))],
            'party_id' => [$creating ? 'required' : 'sometimes', 'string', 'size:26'],
            'issue_date' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', ...($this->filled('issue_date') ? ['after_or_equal:issue_date'] : [])],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*' => ['array:description,quantity,unit_price_minor,account_id,cost_centre_id,tax_code_id'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/'],
            'lines.*.unit_price_minor' => ['required', 'integer', 'min:0', 'max:'.JournalRequest::MAX_AMOUNT],
            'lines.*.account_id' => ['required', 'string', 'size:26'],
            'lines.*.cost_centre_id' => ['nullable', 'string', 'size:26'],
            'lines.*.tax_code_id' => ['nullable', 'string', 'size:26'],
            'submit' => [$creating ? 'sometimes' : 'prohibited', 'boolean'],
        ];
    }
}
