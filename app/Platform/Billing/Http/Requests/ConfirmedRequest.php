<?php

namespace App\Platform\Billing\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * An action the person confirmed in a dialog (e.g. moving to the free plan).
 */
class ConfirmedRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['confirm' => ['required', 'accepted']];
    }
}
