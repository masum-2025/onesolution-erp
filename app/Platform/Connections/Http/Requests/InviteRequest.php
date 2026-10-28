<?php

namespace App\Platform\Connections\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A provider's invitation: its own name for whom it invites (optional).
 */
class InviteRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:150']];
    }
}
