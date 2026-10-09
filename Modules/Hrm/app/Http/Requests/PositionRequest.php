<?php

namespace Modules\Hrm\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;

/**
 * A position, named in every language the platform supports.
 */
class PositionRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $locales = LanguageRegistry::codes();

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'title' => [$creating ? 'required' : 'sometimes', 'array:'.implode(',', $locales)],
            'title.en' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:100'],
            'title.*' => ['nullable', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:30', 'alpha_dash'],
            'grade' => ['nullable', 'string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
            // Where the position belongs is chosen once, at creation.
            'organization_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'size:26'],
        ];
    }
}
