<?php

namespace Modules\EduWebsite\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Sites\SiteResolver;
use App\Platform\Support\Http\StrictFormRequest;

/**
 * Starting a website (address, name, template, language) or changing its
 * settings (with the version seen): names per language, colours as
 * #rrggbb, contact details, logo (a picture of the site).
 */
class SiteRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $starting = $this->isMethod('post');
        $required = $starting ? 'required' : 'sometimes';
        $locales = LanguageRegistry::codes();
        $texts = fn (string $field, int $max, bool $needed) => [
            $field => [$needed ? $required : 'sometimes', 'nullable', 'array:'.implode(',', $locales)],
            "{$field}.*" => ['nullable', 'string', 'max:'.$max],
        ];

        return [
            'base_version' => [$starting ? 'prohibited' : 'required', 'integer', 'min:1'],
            'slug' => [$required, 'string', 'min:3', 'max:63', 'regex:/^'.SiteResolver::SLUG_PATTERN.'$/'],
            ...$texts('title', 120, true),
            'title.en' => [$required, 'string', 'min:2', 'max:120'],
            ...$texts('tagline', 160, false),
            'template' => [$required, 'string', 'max:30'],
            'default_locale' => [$required, 'string', 'in:'.implode(',', $locales)],
            'theme' => ['sometimes', 'array:primary,accent'],
            'theme.primary' => ['required_with:theme', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.accent' => ['required_with:theme', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'contact' => ['sometimes', 'array:address,hours,phone,email,map_url'],
            'contact.address' => ['nullable', 'array:'.implode(',', $locales)],
            'contact.address.*' => ['nullable', 'string', 'max:300'],
            'contact.hours' => ['nullable', 'array:'.implode(',', $locales)],
            'contact.hours.*' => ['nullable', 'string', 'max:200'],
            'contact.phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-\s]+$/'],
            'contact.email' => ['nullable', 'email', 'max:150'],
            'contact.map_url' => ['nullable', 'url:https', 'max:500'],
            'logo_media_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ];
    }
}
