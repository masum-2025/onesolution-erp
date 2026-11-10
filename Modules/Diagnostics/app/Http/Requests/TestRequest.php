<?php

namespace Modules\Diagnostics\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;

/**
 * Making or changing a Test: its fields (money in minor units, quantities in thousandths), an op id when made, its version when changed.
 */
class TestRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $locales = LanguageRegistry::codes();

        return [
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:30'],
            'name' => [$creating ? 'required' : 'sometimes', 'array:'.implode(',', $locales)],
            'name.en' => [$creating ? 'required' : 'sometimes', 'string', 'max:150'],
            'name.*' => ['nullable', 'string', 'max:150'],
            'department' => [$creating ? 'required' : 'sometimes', 'in:haematology,biochemistry,microbiology,immunology,clinical_pathology,imaging,cardiology,other'],
            'sample_type' => [$creating ? 'required' : 'sometimes', 'in:blood,serum,urine,stool,swab,other,none'],
            'service_code' => [$creating ? 'required' : 'sometimes', 'string', 'max:30'],
            'turnaround_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:720'],
            'is_active' => ['sometimes', 'boolean'],
            'op_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
