<?php

namespace Modules\Crm\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A contact made or changed (the version seen when changing). Extra fields are checked against the company's own (crm_fields).
 */
class ContactRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'op_id' => [$creating ? 'sometimes' : 'prohibited', 'nullable', 'string', 'max:64'],
            'unit_id' => [$creating ? 'sometimes' : 'prohibited', 'nullable', 'string', 'max:26'],
            'kind' => ['sometimes', 'in:person,organization'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:1', 'max:150'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'address' => ['sometimes', 'nullable', 'array:line1,line2,city,district,postcode'],
            'address.*' => ['nullable', 'string', 'max:150'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:30'],
            'source' => ['sometimes', 'nullable', 'string', 'max:40'],
            'owner_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'sms_consent' => ['sometimes', 'boolean'],
            'email_consent' => ['sometimes', 'boolean'],
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
            'extra' => ['sometimes', 'array', 'max:40'],
        ];
    }
}
