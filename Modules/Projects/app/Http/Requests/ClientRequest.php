<?php

namespace Modules\Projects\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A new client added from a project (kept in CRM): a person or an
 * organization, its name, the company a person works for, phone, email and
 * address. CRM checks the phone's format and that no contact has it yet.
 */
class ClientRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:person,organization'],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'array:line1,city,district'],
            'address.*' => ['nullable', 'string', 'max:150'],
        ];
    }
}
