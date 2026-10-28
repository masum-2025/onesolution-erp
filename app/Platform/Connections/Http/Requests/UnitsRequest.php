<?php

namespace App\Platform\Connections\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * The client's companies and branches the provider may deal with, all of them.
 */
class UnitsRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'unit_ids' => ['required', 'array', 'min:1', 'max:200'],
            'unit_ids.*' => ['string', 'size:26'],
        ];
    }
}
