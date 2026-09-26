<?php

namespace App\Platform\Notifications\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class MailDomainRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The domain mail comes from, e.g. mail.acme.com (no scheme, no address).
            'domain' => ['required', 'string', 'max:253', 'regex:/^(?=.{4,253}$)(?:(?!-)[a-z0-9-]{1,63}(?<!-)\.)+[a-z]{2,63}$/i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['domain.regex' => __('notifications.validation.domain')];
    }
}
