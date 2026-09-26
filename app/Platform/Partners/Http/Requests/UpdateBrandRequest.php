<?php

namespace App\Platform\Partners\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

class UpdateBrandRequest extends StrictFormRequest
{
    private const COLOR = 'regex:/^#[0-9A-Fa-f]{6}$/';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $locales = implode(',', (array) config('tenancy.supported_locales'));
        $texts = fn (int $max) => [
            ['sometimes', 'nullable', 'array:'.$locales],
            ['nullable', 'string', 'max:'.$max],
        ];

        $rules = [
            'product_name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'primary_color' => ['sometimes', 'nullable', 'string', self::COLOR],
            'secondary_color' => ['sometimes', 'nullable', 'string', self::COLOR],
            'font_key' => ['sometimes', 'nullable', 'string', Rule::in(array_keys((array) config('branding.fonts')))],
            'support_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'support_phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9 ()\-]{5,30}$/'],
            // Legal pages open in a new tab: https links only.
            'terms_url' => ['sometimes', 'nullable', 'string', 'url:https', 'max:500'],
            'privacy_url' => ['sometimes', 'nullable', 'string', 'url:https', 'max:500'],
        ];

        foreach (['tagline' => 120, 'login_title' => 80, 'login_text' => 300, 'footer_text' => 200] as $field => $max) {
            [$rules[$field], $rules[$field.'.*']] = $texts($max);
        }

        return $rules;
    }
}
