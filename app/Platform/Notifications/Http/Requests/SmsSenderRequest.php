<?php

namespace App\Platform\Notifications\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class SmsSenderRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Operators accept 3 to 11 letters, digits or spaces, with at least one letter.
            'sender_id' => ['required', 'string', 'regex:/^(?=.*[A-Za-z])[A-Za-z0-9 ]{3,11}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['sender_id.regex' => __('notifications.validation.sender_id')];
    }
}
