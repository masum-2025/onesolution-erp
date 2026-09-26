<?php

namespace App\Platform\Notifications\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class MailSenderRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The part before @ on the partner's domain.
            'local_part' => ['sometimes', 'required', 'string', 'max:64', 'regex:/^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/i'],
            // Shown as the sender; null = the product name.
            'from_name' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[^<>"\r\n]+$/u'],
            'reply_to' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
        ];
    }
}
