<?php

namespace App\Platform\Partners\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class PoweredByRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['show' => ['required', 'boolean']];
    }
}
