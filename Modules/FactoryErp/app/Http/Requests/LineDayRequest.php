<?php

namespace Modules\FactoryErp\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Who worked on a line on a day: operators, helpers, minutes (the
 * company's shift when left out), a note; base_version to change one
 * already written.
 */
class LineDayRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'line_id' => ['required', 'string', 'size:26'],
            'worked_on' => ['sometimes', 'date_format:Y-m-d'],
            'operators' => ['required', 'integer', 'min:0', 'max:500'],
            'helpers' => ['sometimes', 'integer', 'min:0', 'max:500'],
            'minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
            'base_version' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
