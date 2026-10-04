<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A customer or vendor (or both): name, contact details, own payment terms.
 */
class PartyRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:150'],
            'is_customer' => ['sometimes', 'boolean'],
            'is_vendor' => ['sometimes', 'boolean'],
            'code' => ['nullable', 'string', 'max:30', 'alpha_dash'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{5,30}$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'array:line1,line2,area,city,postcode'],
            'address.*' => ['nullable', 'string', 'max:150'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
        ];
    }
}
