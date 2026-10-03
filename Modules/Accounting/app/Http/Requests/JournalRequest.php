<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A journal entry as written: date, narration and lines in minor units
 * (one side per line). Accounts, cost centres and balance are checked by
 * Journals; "submit" sends it straight away.
 */
class JournalRequest extends StrictFormRequest
{
    /** Most lines one journal may have. */
    public const MAX_LINES = 200;

    /** Largest amount on one line, in minor units (fits every database's big integer). */
    public const MAX_AMOUNT = 999_999_999_999_999;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'entry_date' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'narration' => [$creating ? 'required' : 'sometimes', 'string', 'min:3', 'max:500'],
            'lines' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*' => ['array:account_id,cost_centre_id,debit_minor,credit_minor,memo'],
            'lines.*.account_id' => ['required', 'string', 'size:26'],
            'lines.*.cost_centre_id' => ['nullable', 'string', 'size:26'],
            'lines.*.debit_minor' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT],
            'lines.*.credit_minor' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
            'submit' => [$creating ? 'sometimes' : 'prohibited', 'boolean'],
        ];
    }
}
