<?php

namespace Modules\Zkteco\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Closure;
use Modules\Zkteco\Models\Device;

/**
 * Adding or changing a terminal. Its address must be a private IPv4 on the
 * office network (never a public one: the server would be made to connect
 * anywhere). Its branch is fixed once added.
 */
class DeviceRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'unit_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'size:26'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:80'],
            'host' => [$creating ? 'required' : 'sometimes', 'string', 'ip', function (string $attribute, mixed $value, Closure $fail) {
                $v4 = filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
                $public = filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
                if (! $v4 || $public || str_starts_with((string) $value, '127.') || $value === '0.0.0.0') {
                    $fail(__('zkteco::zkteco.validation.host'));
                }
            }],
            'port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'protocol' => ['sometimes', 'in:'.implode(',', Device::PROTOCOLS)],
            'comm_key' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999'],
            'import_from' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'is_active' => ['sometimes', 'boolean'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
