<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Accounting\Models\OpeningLine;

/**
 * The opening balances as a whole: the date and every line. Account lines
 * carry a debit or a credit; customer and vendor lines an amount owed with
 * the old invoice's reference and dates. base_version once a draft exists.
 */
class OpeningRequest extends StrictFormRequest
{
    /** Most lines one opening takes (a long customer list goes in more than one go later). */
    public const MAX_LINES = 500;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['nullable', 'integer', 'min:1'],
            'opening_date' => ['required', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*' => ['array:kind,account_id,party_id,cost_centre_id,debit_minor,credit_minor,amount_minor,reference,issue_date,due_date'],
            'lines.*.kind' => ['required', 'string', 'in:'.implode(',', OpeningLine::KINDS)],
            'lines.*.account_id' => ['required_if:lines.*.kind,account', 'prohibited_unless:lines.*.kind,account', 'nullable', 'string', 'max:26'],
            'lines.*.party_id' => ['required_unless:lines.*.kind,account', 'prohibited_if:lines.*.kind,account', 'nullable', 'string', 'max:26'],
            'lines.*.cost_centre_id' => ['nullable', 'string', 'max:26'],
            'lines.*.debit_minor' => ['prohibited_unless:lines.*.kind,account', 'nullable', 'integer', 'min:0', 'max:999999999999999'],
            'lines.*.credit_minor' => ['prohibited_unless:lines.*.kind,account', 'nullable', 'integer', 'min:0', 'max:999999999999999'],
            'lines.*.amount_minor' => ['required_unless:lines.*.kind,account', 'prohibited_if:lines.*.kind,account', 'nullable', 'integer', 'min:1', 'max:999999999999999'],
            'lines.*.reference' => ['prohibited_if:lines.*.kind,account', 'nullable', 'string', 'max:100'],
            'lines.*.issue_date' => ['required_unless:lines.*.kind,account', 'prohibited_if:lines.*.kind,account', 'nullable', 'date_format:Y-m-d'],
            'lines.*.due_date' => ['prohibited_if:lines.*.kind,account', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
