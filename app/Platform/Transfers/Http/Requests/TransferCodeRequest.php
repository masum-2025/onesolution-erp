<?php

namespace App\Platform\Transfers\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class TransferCodeRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Who the code is for, so staff recognise it later.
            'label' => ['nullable', 'string', 'max:100'],
        ];
    }
}
