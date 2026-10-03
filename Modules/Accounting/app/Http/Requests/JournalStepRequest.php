<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A step of a journal (submit, approve, reject, withdraw, reverse): the
 * version the person saw, and for reject and reverse a reason.
 */
class JournalStepRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $step = $this->route('step');

        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'reason' => [in_array($step, ['reject', 'reverse'], true) ? 'required' : 'prohibited', 'string', 'min:5', 'max:300'],
            'entry_date' => [$step === 'reverse' ? 'nullable' : 'prohibited', 'date_format:Y-m-d'],
        ];
    }
}
