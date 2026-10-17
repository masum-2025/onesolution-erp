<?php

namespace Modules\FactoryErp\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\FactoryErp\Models\LetterOfCredit;

/**
 * Making or changing a letter of credit: the buyer (fixed once made),
 * number, banks, currency and amount (minor units) with a tolerance
 * (basis points), dates, partial shipments and transshipment, ports,
 * payment days (0 = at sight), the documents it asks for, the orders it
 * pays for, open or closed, a note.
 */
class LetterOfCreditRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $needed = $creating ? 'required' : 'sometimes';

        return [
            'buyer_id' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'],
            'number' => [$needed, 'string', 'min:2', 'max:60'],
            'issued_on' => [$needed, 'date_format:Y-m-d'],
            'issuing_bank' => [$needed, 'string', 'min:2', 'max:150'],
            'advising_bank' => ['sometimes', 'nullable', 'string', 'max:150'],
            'currency' => [$needed, 'string', 'regex:/^[A-Z]{3}$/'],
            'amount_minor' => [$needed, 'integer', 'min:1', 'max:100000000000000'],
            'tolerance_bp' => ['sometimes', 'integer', 'min:0', 'max:2000'],
            'latest_ship_on' => [$needed, 'date_format:Y-m-d'],
            'expires_on' => [$needed, 'date_format:Y-m-d'],
            'partial_allowed' => ['sometimes', 'boolean'],
            'transshipment_allowed' => ['sometimes', 'boolean'],
            'port_loading' => ['sometimes', 'nullable', 'string', 'max:100'],
            'port_discharge' => ['sometimes', 'nullable', 'string', 'max:100'],
            'payment_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'documents' => ['sometimes', 'array', 'max:20'],
            'documents.*' => ['string', 'in:'.implode(',', LetterOfCredit::DOCUMENTS)],
            'order_ids' => ['sometimes', 'array', 'max:100'],
            'order_ids.*' => ['string', 'size:26', 'distinct'],
            'status' => [$creating ? 'prohibited' : 'sometimes', 'in:open,closed'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
