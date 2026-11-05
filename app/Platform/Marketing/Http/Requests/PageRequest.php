<?php

namespace App\Platform\Marketing\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;

/** A website page: its address, title, search details and place in the menu. */
class PageRequest extends StrictFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $codes = implode(',', LanguageRegistry::codes());

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'slug' => [$creating ? 'required' : 'sometimes', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'title' => [$creating ? 'required' : 'sometimes', 'array:'.$codes, 'min:1'],
            'title.*' => ['nullable', 'string', 'max:80'],
            'seo' => ['sometimes', 'nullable', 'array:title,description,image_url,noindex'],
            'seo.title' => ['nullable', 'array:'.$codes], 'seo.title.*' => ['nullable', 'string', 'max:70'],
            'seo.description' => ['nullable', 'array:'.$codes], 'seo.description.*' => ['nullable', 'string', 'max:170'],
            'seo.image_url' => ['nullable', 'string', 'max:300', 'regex:#^(https://[^\s<>"]+|/[^\s<>"]*)$#i'],
            'seo.noindex' => ['nullable', 'boolean'],
            'in_menu' => ['sometimes', 'boolean'],
        ];
    }
}
