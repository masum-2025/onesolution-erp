<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Accounting\Enums\SettlementType;

/**
 * Money received from a customer or paid to a vendor: when, into or out of
 * which account, how much, and which documents it pays. op_id (the app's
 * own id for this record) makes sending it twice harmless.
 */
class SettlementRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:'.implode(',', array_column(SettlementType::cases(), 'value'))],
            'party_id' => ['required', 'string', 'size:26'],
            'settled_on' => ['required', 'date_format:Y-m-d'],
            'account_id' => ['required', 'string', 'size:26'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:'.JournalRequest::MAX_AMOUNT],
            'reference' => ['nullable', 'string', 'max:100'],
            'memo' => ['nullable', 'string', 'max:500'],
            'op_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'allocations' => ['sometimes', 'array', 'max:'.DocumentStepRequest::MAX_ALLOCATIONS],
            'allocations.*' => ['array:document_id,amount_minor'],
            'allocations.*.document_id' => ['required', 'string', 'size:26'],
            'allocations.*.amount_minor' => ['required', 'integer', 'min:1', 'max:'.JournalRequest::MAX_AMOUNT],
        ];
    }
}
