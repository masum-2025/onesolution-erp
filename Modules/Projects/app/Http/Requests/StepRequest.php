<?php

namespace Modules\Projects\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** A project's next step: the version seen, and a reason to hold, cancel or reopen it. */
class StepRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $reason = in_array($this->route('step'), ['hold', 'cancel', 'reopen'], true) ? 'required' : 'nullable';

        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'reason' => [$reason, 'string', 'min:3', 'max:500'],
        ];
    }
}
