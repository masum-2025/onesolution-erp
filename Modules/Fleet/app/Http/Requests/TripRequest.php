<?php

namespace Modules\Fleet\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Making or changing a Trip: its fields (money in minor units, quantities in thousandths), an op id when made, its version when changed.
 */
class TripRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'purpose' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'vehicle_id' => [$creating ? 'required' : 'sometimes', 'string', 'size:26'],
            'driver_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'trip_on' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'destination' => ['sometimes', 'nullable', 'string', 'max:120'],
            'distance_milli' => ['sometimes', 'nullable', 'integer', 'min:-1000000000000000', 'max:1000000000000000'],
            'fuel_cost_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000000000'],
            'op_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
