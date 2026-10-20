<?php

namespace Modules\EduWebsite\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;
use Modules\EduWebsite\Http\Requests\Concerns\MainLocale;

/**
 * A page: its address (letters, digits, hyphens), title per language (the
 * site's main language required), place in the menu, a description and a
 * picture for search and sharing; the version seen when changing.
 */
class PageRequest extends StrictFormRequest
{
    use MainLocale;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';
        $main = $this->mainLocale();
        $codes = implode(',', LanguageRegistry::codes());

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'slug' => [$required, 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'not_in:notices,media,preview,home'],
            'title' => [$required, 'array:'.$codes],
            "title.{$main}" => [$creating ? 'required' : 'required_with:title', 'string', 'min:1', 'max:80'],
            'title.*' => ['nullable', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'array:'.$codes],
            'description.*' => ['nullable', 'string', 'max:160'],
            'share_media_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'in_menu' => ['sometimes', 'boolean'],
            'sort_order' => [$creating ? 'prohibited' : 'sometimes', 'integer', 'min:0', 'max:999'],
        ];
    }
}
