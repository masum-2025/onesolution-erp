<?php

namespace App\Platform\Transfers\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Where a client wants to move: a partner's transfer code, or back to the
 * house partner. The request itself (not the preview) also needs a reason
 * and the owner's explicit consent.
 */
class TransferRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $requesting = ! str_ends_with((string) $this->path(), '/preview');

        return [
            'code' => ['required_without:to_house', 'nullable', 'string', 'max:20'],
            'to_house' => ['sometimes', 'boolean'],
            'reason' => $requesting ? ['required', 'string', 'min:5', 'max:500'] : ['prohibited'],
            'consent' => $requesting ? ['required', 'accepted'] : ['prohibited'],
        ];
    }

    public function toHouse(): bool
    {
        return (bool) $this->validated('to_house', false) && $this->validated('code') === null;
    }
}
