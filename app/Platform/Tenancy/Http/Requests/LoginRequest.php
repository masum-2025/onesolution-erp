<?php

namespace App\Platform\Tenancy\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class LoginRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // An email, or a verified phone number with its country (Phase 5C).
            'email' => ['required_without:phone', 'nullable', 'string', 'email', 'max:255'],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:30', 'regex:/^[\d\s()+\-]+$/'],
            'country_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
