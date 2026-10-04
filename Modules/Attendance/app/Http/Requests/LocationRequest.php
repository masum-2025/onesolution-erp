<?php

namespace Modules\Attendance\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A workplace: its name, point in millionths of a degree (23.810331 =
 * 23810331), radius in metres (default: the rule attendance.geo_radius_m),
 * and the unit it is for (default: the unit in the address).
 */
class LocationRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'unit_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:26'],
            'name' => [$required, 'string', 'min:2', 'max:120'],
            'latitude_micro' => [$required, 'integer', 'between:-90000000,90000000'],
            'longitude_micro' => [$required, 'integer', 'between:-180000000,180000000'],
            'radius_m' => ['sometimes', 'nullable', 'integer', 'min:20', 'max:5000'],
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
        ];
    }
}
