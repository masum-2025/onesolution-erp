<?php

namespace App\Platform\Meta\Http;

use App\Platform\Support\Http\StrictFormRequest;

/** A partner's Meta app: a change (id, secret), an approval or a rejection. */
class MetaAppRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->routeIs('meta.app.reject')) {
            return ['base_version' => ['required', 'integer', 'min:1']];
        }
        if ($this->routeIs('meta.app.approve')) {
            return ['base_version' => ['required', 'integer', 'min:1'], 'password' => ['required', 'string', 'max:200']];
        }

        return [
            'base_version' => ['nullable', 'integer', 'min:1'],
            'app_id' => ['required', 'string', 'regex:/^\d{6,30}$/'],
            'app_secret' => ['required', 'string', 'regex:/^[A-Za-z0-9]{16,64}$/'],
            'password' => ['required', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'app_id.regex' => __('meta.validation.app_id'),
            'app_secret.regex' => __('meta.validation.app_secret'),
        ];
    }
}
