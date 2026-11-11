<?php

namespace Modules\Outpatient\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Making or changing a Chamber: its fields (money in minor units, quantities in thousandths), an op id when made, its version when changed.
 */
class ChamberRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'room' => ['sometimes', 'nullable', 'string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
            'op_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
