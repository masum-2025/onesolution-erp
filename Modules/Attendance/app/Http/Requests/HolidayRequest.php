<?php

namespace Modules\Attendance\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** A day off: the date, its name, and optionally the unit it is for (else the whole company). */
class HolidayRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $locales = (array) config('tenancy.supported_locales');

        return [
            'on' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'array:'.implode(',', $locales)],
            'name.en' => ['required', 'string', 'min:2', 'max:80'],
            'name.*' => ['nullable', 'string', 'max:80'],
            'unit_id' => ['nullable', 'string', 'max:26'],
        ];
    }
}
