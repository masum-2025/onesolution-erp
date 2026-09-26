<?php

namespace App\Platform\Identity\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class ResendRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['challenge_id' => ['required', 'string', 'ulid']];
    }
}
