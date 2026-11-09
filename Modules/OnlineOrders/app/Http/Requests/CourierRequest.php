<?php

namespace Modules\OnlineOrders\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\OnlineOrders\Couriers\CourierDrivers;

/**
 * SC-5b: a courier account added (which courier, a name, its API keys) or
 * changed (with the version seen). Which keys each courier needs is checked
 * by Couriers against the courier's driver.
 */
class CourierRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'driver' => [$creating ? 'required' : 'prohibited', 'string', 'in:'.implode(',', array_keys(CourierDrivers::DRIVERS))],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:80'],
            'credentials' => [$creating ? 'required' : 'sometimes', 'array', 'max:5'],
            'credentials.*' => ['string', 'max:200'],
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
            'new_token' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
        ];
    }
}
