<?php

namespace App\Platform\Identity\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * "Delete my account": the password, and the word DELETE typed on purpose.
 */
class DeletionRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:255'],
            'confirm' => ['required', 'string', 'max:20'],
        ];
    }
}
