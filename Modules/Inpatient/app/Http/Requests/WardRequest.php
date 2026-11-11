<?php

namespace Modules\Inpatient\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;

/**
 * Making or changing a Ward or cabin: its fields (money in minor units, quantities in thousandths), an op id when made, its version when changed.
 */
class WardRequest extends StrictFormRequest
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
            'name.en' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'name.*' => ['nullable', 'string', 'max:100'],
            'kind' => [$creating ? 'required' : 'sometimes', 'in:general,cabin,icu,hdu,nicu,other'],
            'is_active' => ['sometimes', 'boolean'],
            'op_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
