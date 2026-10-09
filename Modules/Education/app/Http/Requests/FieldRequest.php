<?php

namespace Modules\Education\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;
use Modules\Education\Models\Field;

/**
 * An own field made (entity, key and type fixed from then on) or changed
 * (label, options, order, required, portal, documents, sensitive, on or off).
 */
class FieldRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $languages = 'array:'.implode(',', LanguageRegistry::codes());

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'entity' => [$creating ? 'required' : 'prohibited', 'in:'.implode(',', Field::ENTITIES)],
            'key' => [$creating ? 'required' : 'prohibited', 'string', 'regex:/^[a-z][a-z0-9_]{1,39}$/'],
            'type' => [$creating ? 'required' : 'prohibited', 'in:'.implode(',', Field::TYPES)],
            'label' => [$creating ? 'required' : 'sometimes', $languages],
            'label.*' => ['nullable', 'string', 'max:80'],
            'label.en' => [$creating ? 'required' : 'sometimes', 'string', 'min:1', 'max:80'],
            'options' => ['sometimes', 'nullable', 'array', 'max:50'],
            'options.*' => ['array:value,label'],
            'options.*.value' => ['required', 'string', 'regex:/^[A-Za-z0-9_+-]{1,40}$/', 'distinct'],
            'options.*.label' => ['required', $languages],
            'options.*.label.*' => ['nullable', 'string', 'max:80'],
            'is_required' => ['sometimes', 'boolean'],
            'portal_visible' => ['sometimes', 'boolean'],
            'on_documents' => ['sometimes', 'boolean'],
            'is_sensitive' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
        ];
    }
}
