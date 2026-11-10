<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;

/**
 * Making or changing a Generic: its fields (money in minor units, quantities in thousandths), an op id when made, its version when changed.
 */
class GenericRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $locales = LanguageRegistry::codes();

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'array:'.implode(',', $locales)],
            'name.en' => [$creating ? 'required' : 'sometimes', 'string', 'max:150'],
            'name.*' => ['nullable', 'string', 'max:150'],
            'drug_class' => ['sometimes', 'nullable', 'string', 'max:60'],
            'is_controlled' => ['sometimes', 'boolean'],
            'controlled_schedule' => ['sometimes', 'nullable', 'string', 'max:20'],
            'in_use' => ['sometimes', 'boolean'],
            'op_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
