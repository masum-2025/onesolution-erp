<?php

namespace Modules\Fleet\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Making or changing a Vehicle: its fields (money in minor units, quantities in thousandths), an op id when made, its version when changed.
 */
class VehicleRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'registration_no' => [$creating ? 'required' : 'sometimes', 'string', 'max:30'],
            'model' => ['sometimes', 'nullable', 'string', 'max:80'],
            'kind' => [$creating ? 'required' : 'sometimes', 'in:truck,van,car,motorcycle'],
            'capacity_milli' => ['sometimes', 'nullable', 'integer', 'min:-1000000000000000', 'max:1000000000000000'],
            'bought_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'price_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000000000'],
            'in_use' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'op_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
