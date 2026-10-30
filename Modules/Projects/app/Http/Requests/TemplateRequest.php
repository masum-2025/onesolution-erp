<?php

namespace Modules\Projects\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;

/**
 * A template: its name per language, the service it is for, and all its
 * steps (title per language, due days after the start). The version seen
 * when changing it.
 */
class TemplateRequest extends StrictFormRequest
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
            'name' => ['required', $languages],
            'name.en' => ['required', 'string', 'max:120'],
            'name.*' => ['nullable', 'string', 'max:120'],
            'service' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:200'],
            'steps.*' => ['array:title,due_after_days'],
            'steps.*.title' => ['required', $languages],
            'steps.*.title.en' => ['required', 'string', 'max:200'],
            'steps.*.title.*' => ['nullable', 'string', 'max:200'],
            'steps.*.due_after_days' => ['required', 'integer', 'min:0', 'max:3650'],
        ];
    }
}
