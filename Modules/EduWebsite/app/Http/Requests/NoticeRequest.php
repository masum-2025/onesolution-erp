<?php

namespace Modules\EduWebsite\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;

/** A notice: title and plain text per language, an optional PDF of the site, the date it shows from, pinned, published. */
class NoticeRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';
        $locales = implode(',', LanguageRegistry::codes());

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'title' => [$required, 'array:'.$locales],
            'title.en' => [$required, 'string', 'min:2', 'max:200'],
            'title.*' => ['nullable', 'string', 'max:200'],
            'body' => ['sometimes', 'nullable', 'array:'.$locales],
            'body.*' => ['nullable', 'string', 'max:5000'],
            'media_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'published_on' => [$required, 'date_format:Y-m-d'],
            'pinned' => ['sometimes', 'boolean'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }
}
