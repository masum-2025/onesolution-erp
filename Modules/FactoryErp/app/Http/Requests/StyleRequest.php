<?php

namespace Modules\FactoryErp\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Making or changing a style: the buyer (a CRM buyer), style number, name,
 * kind of product, sizes and colours in order, a note and own fields.
 * Taking out a size or colour an order uses is checked by Styles.
 */
class StyleRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'buyer_id' => [$creating ? 'required' : 'sometimes', 'string', 'size:26'],
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'min:1', 'max:40'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:150'],
            'product_type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'sizes' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:30'],
            'sizes.*' => ['required', 'string', 'max:20'],
            'colors' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:40'],
            'colors.*' => ['required', 'string', 'max:40'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'extra' => ['sometimes', 'array'],
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
