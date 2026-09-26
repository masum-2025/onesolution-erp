<?php

namespace App\Platform\Transfers\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class TransferDecisionRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Rejecting needs a reason the client can read; accepting may add a note.
        $rejecting = str_ends_with((string) $this->path(), '/reject');

        return [
            'note' => [$rejecting ? 'required' : 'nullable', 'string', 'min:5', 'max:500'],
        ];
    }
}
