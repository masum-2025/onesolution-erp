<?php

namespace App\Platform\Marketing\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** Pages or sections in their new order. */
class OrderRequest extends StrictFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'ids.*' => ['required', 'string', 'size:26', 'distinct'],
        ];
    }
}
