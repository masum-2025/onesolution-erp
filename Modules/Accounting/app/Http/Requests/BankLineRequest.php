<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A step on a statement line: match it to book lines, or write the entry
 * the books are missing (the other account and the words of the entry).
 */
class BankLineRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $step = $this->route('step');

        return [
            'journal_line_ids' => [$step === 'match' ? 'required' : 'prohibited', 'array', 'min:1', 'max:50'],
            'journal_line_ids.*' => ['string', 'max:26', 'distinct'],
            'account_id' => [$step === 'entry' ? 'required' : 'prohibited', 'string', 'max:26'],
            'narration' => [$step === 'entry' ? 'required' : 'prohibited', 'string', 'min:3', 'max:500'],
            'cost_centre_id' => [$step === 'entry' ? 'nullable' : 'prohibited', 'string', 'max:26'],
        ];
    }
}
