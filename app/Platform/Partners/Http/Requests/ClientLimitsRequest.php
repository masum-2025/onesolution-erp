<?php

namespace App\Platform\Partners\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class ClientLimitsRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Per limit: a number for a deal, or null to follow the plan again.
            'limits' => ['required', 'array:users,branches,storage_mb', 'min:1'],
            'limits.users' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'limits.branches' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'limits.storage_mb' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
