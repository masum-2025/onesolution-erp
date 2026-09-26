<?php

namespace App\Platform\Notifications\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A partner's wording for one channel and language: plain text with
 * {{ placeholders }}. SMS has no subject and stays short.
 */
class TemplateRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sms = $this->route('channel') === 'sms';

        return [
            'subject' => $sms ? ['prohibited'] : ['required', 'string', 'min:3', 'max:200', 'regex:/^[^\r\n]+$/u'],
            'body' => ['required', 'string', 'min:3', 'max:'.($sms ? 480 : 5000)],
        ];
    }
}
