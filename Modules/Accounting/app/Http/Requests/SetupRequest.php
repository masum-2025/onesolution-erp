<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Start the company's books: a chart template (the rule's suggestion when
 * left out) and, optionally, the first day of the first fiscal year.
 */
class SetupRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'template' => ['nullable', 'string', 'max:50'],
            'first_year_starts_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
