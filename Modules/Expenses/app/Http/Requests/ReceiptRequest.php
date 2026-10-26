<?php

namespace Modules\Expenses\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** A receipt for a draft claim (kind and size checked from the file itself), for one of its lines or the whole claim. */
class ReceiptRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'line_id' => ['nullable', 'string', 'size:26'],
        ];
    }
}
