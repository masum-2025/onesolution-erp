<?php

namespace Modules\Patients\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Approving or rejecting someone else's merge request; a note is needed to reject.
 */
class DecideMergeRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'approve' => ['required', 'boolean'],
            'note' => ['nullable', 'required_if:approve,false', 'string', 'max:300'],
        ];
    }
}
