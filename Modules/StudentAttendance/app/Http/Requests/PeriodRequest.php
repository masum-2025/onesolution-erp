<?php

namespace Modules\StudentAttendance\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;

/** Adding or changing a campus's period (its campus is fixed once added). */
class PeriodRequest extends StrictFormRequest
{
    /** Periods a day may have. */
    private const MAX_NUMBER = 20;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $locales = LanguageRegistry::codes();

        return [
            'unit_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'size:26'],
            'number' => [$creating ? 'required' : 'sometimes', 'integer', 'min:1', 'max:'.self::MAX_NUMBER],
            'name' => [$creating ? 'required' : 'sometimes', 'array:'.implode(',', $locales)],
            'name.en' => [$creating ? 'required' : 'sometimes', 'string', 'max:40'],
            'name.*' => ['nullable', 'string', 'max:40'],
            'starts_at' => ['sometimes', 'nullable', 'date_format:H:i'],
            'ends_at' => ['sometimes', 'nullable', 'date_format:H:i', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
