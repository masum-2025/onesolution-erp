<?php

namespace Modules\EduWebsite\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;

/** A page: its address (letters, digits, hyphens), title per language, place in the menu; the version seen when changing. */
class PageRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'slug' => [$required, 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'not_in:notices,media,preview,home'],
            'title' => [$required, 'array:'.implode(',', LanguageRegistry::codes())],
            'title.en' => [$required, 'string', 'min:1', 'max:80'],
            'title.*' => ['nullable', 'string', 'max:80'],
            'in_menu' => ['sometimes', 'boolean'],
            'sort_order' => [$creating ? 'prohibited' : 'sometimes', 'integer', 'min:0', 'max:999'],
        ];
    }
}
