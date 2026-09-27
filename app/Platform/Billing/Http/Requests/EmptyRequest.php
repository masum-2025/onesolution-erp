<?php

namespace App\Platform\Billing\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * An action without input: any field sent is refused.
 */
class EmptyRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
