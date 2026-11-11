<?php

namespace Modules\Outpatient\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;

/**
 * Making or changing a Doctor: its fields (money in minor units, quantities in thousandths), an op id when made, its version when changed.
 */
class DoctorRequest extends StrictFormRequest
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
            'registration_no' => ['sometimes', 'nullable', 'string', 'max:30'],
            'degrees' => ['sometimes', 'nullable', 'string', 'max:200'],
            'specialty' => ['sometimes', 'nullable', 'string', 'max:150'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:20'],
            'fee_service_code' => [$creating ? 'required' : 'sometimes', 'string', 'max:30'],
            'follow_up_service_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
            'op_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
