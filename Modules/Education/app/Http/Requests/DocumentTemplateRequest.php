<?php

namespace Modules\Education\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;
use Modules\Education\Models\DocumentTemplate;
use Modules\Education\Services\DocumentLayout;

/**
 * A design made (kind fixed from then on) or changed. The page, the items
 * and the questions are checked in depth by DocumentLayout (which keeps
 * only what it knows); here only their outline and size.
 */
class DocumentTemplateRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'kind' => [$creating ? 'required' : 'prohibited', 'in:'.implode(',', DocumentTemplate::KINDS)],
            'name' => [$creating ? 'required' : 'sometimes', 'array:'.implode(',', LanguageRegistry::codes())],
            'name.*' => ['nullable', 'string', 'max:80'],
            'name.en' => [$creating ? 'required' : 'sometimes', 'string', 'min:1', 'max:80'],
            'locale' => [$creating ? 'required' : 'sometimes', 'in:'.implode(',', LanguageRegistry::codes())],
            'page' => [$creating ? 'required' : 'sometimes', 'array:size,orientation'],
            'layout' => [$creating ? 'required' : 'sometimes', 'array:background,elements'],
            'inputs' => ['sometimes', 'nullable', 'array', 'max:'.DocumentLayout::MAX_INPUTS],
            'status' => [$creating ? 'prohibited' : 'sometimes', 'in:'.implode(',', DocumentTemplate::STATUSES)],
        ];
    }

    /** The whole design is a few hundred items at most. */
    protected function prepareForValidation(): void
    {
        if (strlen((string) json_encode($this->input('layout'))) > 200_000) {
            $this->merge(['layout' => 'too_large']);
        }
    }
}
