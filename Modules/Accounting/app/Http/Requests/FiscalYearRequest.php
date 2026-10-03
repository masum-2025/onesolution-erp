<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Add the next fiscal year. Only the first one may start on a date of the
 * company's choosing; later ones follow without a gap.
 */
class FiscalYearRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
