<?php

namespace Modules\Diagnostics\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Making or changing a Referring doctor: its fields (money in minor units, quantities in thousandths), an op id when made, its version when changed.
 */
class ReferrerRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:150'],
            'registration_no' => ['sometimes', 'nullable', 'string', 'max:30'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:20'],
            'commission_percent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'op_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
