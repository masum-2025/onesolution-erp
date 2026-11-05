<?php

namespace App\Platform\Marketing\Http\Requests;

use App\Platform\Marketing\Services\SiteSettings;
use App\Platform\Support\Http\StrictFormRequest;

/** A website's template, languages and settings (the settings' own fields are checked by SiteSettings). */
class UpdateWebsiteRequest extends StrictFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            ...SiteSettings::siteRules(),
            'settings' => ['sometimes', 'array'],
        ];
    }
}
