<?php

namespace Modules\Recruitment\Http\Requests;

use App\Platform\Sites\SiteResolver;
use App\Platform\Support\Http\StrictFormRequest;

/** The careers page address: lower-case letters, digits and hyphens ("/careers/{slug}"). */
class CareersRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'min:3', 'max:63', 'regex:/^'.SiteResolver::SLUG_PATTERN.'$/'],
        ];
    }
}
