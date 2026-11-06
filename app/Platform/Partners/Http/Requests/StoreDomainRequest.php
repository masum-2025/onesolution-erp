<?php

namespace App\Platform\Partners\Http\Requests;

use App\Platform\Partners\Enums\DomainServes;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

class StoreDomainRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A full host name such as erp.partner.com (no scheme, port, path or IP address).
            'host' => ['required', 'string', 'max:253', 'regex:/^(?=.{4,253}$)(?:(?!-)[a-z0-9-]{1,63}(?<!-)\.)+[a-z]{2,63}$/i'],
            // A client's own domain: the client's top organization.
            'organization_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            // What it opens: the app (default) or the partner's company website.
            'serves' => ['sometimes', Rule::enum(DomainServes::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['host.regex' => __('partners.validation.host')];
    }
}
