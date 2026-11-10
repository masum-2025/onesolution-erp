<?php

namespace Modules\PatientBilling\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;

/**
 * Making or changing a Service: its fields (money in minor units, quantities in thousandths), an op id when made, its version when changed.
 */
class ServiceRequest extends StrictFormRequest
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
            'category' => [$creating ? 'required' : 'sometimes', 'in:consultation,test,bed,procedure,other'],
            'price_minor' => [$creating ? 'required' : 'sometimes', 'integer', 'min:0', 'max:100000000000000'],
            'tax_code_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'is_active' => ['sometimes', 'boolean'],
            'op_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
