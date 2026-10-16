<?php

namespace Modules\FactoryErp\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A sewing line: code (unique in the company), name, machines; switched
 * off with is_active.
 */
class LineRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'regex:/^[A-Za-z0-9\-]{1,20}$/'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:1', 'max:100'],
            'machines' => ['sometimes', 'integer', 'min:0', 'max:500'],
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
