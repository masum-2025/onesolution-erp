<?php

namespace App\Platform\Modules\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class ReasonRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
