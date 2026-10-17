<?php

namespace Modules\FactoryErp\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;

/**
 * A kind of defect: code (unique in the company), name in each language,
 * order in the list; switched off with is_active.
 */
class DefectTypeRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $locales = LanguageRegistry::codes();

        return [
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'regex:/^[A-Za-z0-9\-_]{1,20}$/'],
            'name' => [$creating ? 'required' : 'sometimes', 'array:'.implode(',', $locales)],
            'name.en' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:80'],
            'name.*' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
