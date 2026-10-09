<?php

namespace Modules\Hrm\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;
use Modules\Hrm\Enums\CustomFieldType;

/**
 * An extra employee field. Key and type are chosen once (values depend on
 * them); labels and options are named in every supported language.
 */
class CustomFieldRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $locales = implode(',', LanguageRegistry::codes());

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'organization_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'size:26'],
            'key' => [$creating ? 'required' : 'prohibited', 'string', 'regex:/^[a-z][a-z0-9_]{1,39}$/'],
            'type' => [$creating ? 'required' : 'prohibited', 'string', Rule::enum(CustomFieldType::class)],
            'label' => [$creating ? 'required' : 'sometimes', 'array:'.$locales],
            'label.en' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:100'],
            'label.*' => ['nullable', 'string', 'max:100'],
            'options' => ['sometimes', 'nullable', 'array', 'max:50'],
            'options.*' => ['array:value,label'],
            'options.*.value' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'options.*.label' => ['required', 'array:'.$locales],
            'options.*.label.en' => ['required', 'string', 'max:100'],
            'options.*.label.*' => ['nullable', 'string', 'max:100'],
            'is_required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
