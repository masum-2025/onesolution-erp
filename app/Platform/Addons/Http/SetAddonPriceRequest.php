<?php

namespace App\Platform\Addons\Http;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A partner's own monthly price for its clients (amount_minor null = use
 * the platform's price again), or, from the house, the platform's own list
 * or wholesale price (null = not sold in that currency).
 */
class SetAddonPriceRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $platform = str_ends_with($this->path(), '/platform');

        return [
            'kind' => [$platform ? 'required' : 'prohibited', 'in:list,wholesale'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'amount_minor' => ['present', 'nullable', 'integer', 'min:0', 'max:100000000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
