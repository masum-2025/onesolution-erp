<?php

namespace App\Platform\Appearance\Http;

use App\Platform\Appearance\AppearanceResolver;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

/**
 * The person's own look. null clears a choice (back to the organization's).
 * Whether a template or color is locked is not checked here: the choice is
 * kept and applies wherever nobody locked it.
 */
class AppearanceRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $appearance = app(AppearanceResolver::class);

        return collect(['template', 'accent', 'color_vision', 'contrast'])
            ->mapWithKeys(fn (string $field) => [$field => ['sometimes', 'nullable', 'string', Rule::in($appearance->options($field))]])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['*.in' => __('identity.appearance.invalid')];
    }
}
