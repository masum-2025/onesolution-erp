<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Close a fiscal year (the version the person saw) or ask to reopen it (a
 * reason, in the audit log); approve a reopening, or reject it with an
 * optional note.
 */
class YearStepRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $step = $this->route('step');

        return [
            'base_version' => [$step === 'close' ? 'required' : 'prohibited', 'integer', 'min:1'],
            'reason' => [$step === 'reopen' ? 'required' : 'prohibited', 'string', 'min:5', 'max:500'],
            'note' => [$step === 'reject' ? 'nullable' : 'prohibited', 'string', 'max:500'],
        ];
    }
}
