<?php

namespace App\Platform\Notifications\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class TestSmsRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // International format: +8801712345678.
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['phone.regex' => __('notifications.validation.phone')];
    }
}
