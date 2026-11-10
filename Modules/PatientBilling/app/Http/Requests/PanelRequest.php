<?php

namespace Modules\PatientBilling\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Making or changing a Corporate or panel client: its fields (money in minor units, quantities in thousandths), an op id when made, its version when changed.
 */
class PanelRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:150'],
            'kind' => [$creating ? 'required' : 'sometimes', 'in:corporate,panel'],
            'credit_limit_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000000000'],
            'discount_percent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'op_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
