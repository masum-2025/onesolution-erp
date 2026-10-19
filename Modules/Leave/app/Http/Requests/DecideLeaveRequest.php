<?php

namespace Modules\Leave\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Deciding a step of a leave request or cancelling it, with the version
 * seen: approve with an optional note, reject with an optional note,
 * cancel with a reason.
 */
class DecideLeaveRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $cancel = $this->route('step') === 'cancel' || str_ends_with($this->path(), '/cancel');

        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'note' => [$cancel ? 'required' : 'nullable', 'string', $cancel ? 'min:3' : 'min:1', 'max:500'],
        ];
    }
}
