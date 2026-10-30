<?php

namespace Modules\Projects\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** A project's tasks in their new order (ids). */
class OrderRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'max:1000'],
            'ids.*' => ['string', 'size:26'],
        ];
    }
}
