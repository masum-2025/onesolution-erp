<?php

namespace Modules\StudentAttendance\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;
use Closure;

/** A holiday: one day or several, the whole institution or one campus, every class or some. */
class HolidayRequest extends StrictFormRequest
{
    /** Longest holiday in one entry (a long vacation). */
    private const MAX_DAYS = 120;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'unit_id' => ['nullable', 'string', 'size:26'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (is_string($value) && strtotime($value) - strtotime((string) $this->input('starts_on')) > self::MAX_DAYS * 86400) {
                        $fail(__('student_attendance::attendance.validation.holiday_too_long', ['days' => self::MAX_DAYS]));
                    }
                }],
            'name' => ['required', 'array:'.implode(',', LanguageRegistry::codes())],
            'name.en' => ['required', 'string', 'max:80'],
            'name.*' => ['nullable', 'string', 'max:80'],
            'level_ids' => ['nullable', 'array', 'max:50'],
            'level_ids.*' => ['string', 'size:26'],
        ];
    }
}
