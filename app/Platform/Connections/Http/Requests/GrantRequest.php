<?php

namespace App\Platform\Connections\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * What a connected provider may do in one module of the client (NET-2): the
 * module's own actions, optionally until a day (the client's calendar day, inclusive).
 */
class GrantRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['required', 'array', 'min:1', 'max:50'],
            'permissions.*' => ['string', 'max:120'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }
}
