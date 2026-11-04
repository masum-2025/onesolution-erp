<?php

namespace Modules\Letters\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;
use Modules\Letters\Models\LetterTemplate;
use Modules\Letters\Services\LetterLayout;

/**
 * A letter design made or changed. The page, letterhead, inputs and bodies
 * are checked in depth by LetterLayout (which keeps only what it knows);
 * here only their outline and size.
 */
class TemplateRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'name' => [$creating ? 'required' : 'sometimes', 'array:'.implode(',', LanguageRegistry::codes())],
            'name.*' => ['nullable', 'string', 'max:80'],
            'name.en' => [$creating ? 'required' : 'sometimes', 'string', 'min:1', 'max:80'],
            'kind' => [$creating ? 'required' : 'sometimes', 'in:'.implode(',', LetterLayout::KINDS)],
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:10'],
            'page' => ['sometimes', 'array:size,margin_mm'],
            'letterhead' => ['sometimes', 'array:style,logo,image_id,address,align'],
            'inputs' => ['sometimes', 'nullable', 'array', 'max:10'],
            'bodies' => [$creating ? 'required' : 'sometimes', 'array'],
            'status' => [$creating ? 'prohibited' : 'sometimes', 'in:'.implode(',', [LetterTemplate::DRAFT, LetterTemplate::ACTIVE, LetterTemplate::RETIRED])],
        ];
    }

    /** A design is a few dozen blocks per language at most. */
    protected function prepareForValidation(): void
    {
        if (strlen((string) json_encode($this->input('bodies'))) > 200_000) {
            $this->merge(['bodies' => 'too_large']);
        }
    }
}
