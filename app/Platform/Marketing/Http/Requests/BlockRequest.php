<?php

namespace App\Platform\Marketing\Http\Requests;

use App\Platform\Marketing\Services\BlockTypes;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

/** A section of a page: its kind (when added), its data (checked by BlockTypes) and when it shows. */
class BlockRequest extends StrictFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'type' => [$creating ? 'required' : 'prohibited', 'string', Rule::in(BlockTypes::TYPES)],
            'data' => [$creating ? 'required' : 'sometimes', 'array'],
            'is_visible' => ['sometimes', 'boolean'],
            'visible_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'visible_until' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:visible_from'],
        ];
    }
}
