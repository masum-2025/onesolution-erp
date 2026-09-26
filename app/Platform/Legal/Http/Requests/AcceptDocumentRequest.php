<?php

namespace App\Platform\Legal\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class AcceptDocumentRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The version the owner read; a newer one must be read first.
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
