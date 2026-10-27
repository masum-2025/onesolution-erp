<?php

namespace Modules\Recruitment\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;

/**
 * A job opening: its title and description per language, the unit and HRM
 * position, the kind of employment, how many people, a salary range in
 * minor units (shown or not), public or not, the last day to apply; the
 * version seen when changing.
 */
class OpeningRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $languages = implode(',', LanguageRegistry::codes());

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'title' => [$creating ? 'required' : 'sometimes', 'array:'.$languages],
            'title.en' => [$creating ? 'required' : 'required_with:title', 'string', 'min:2', 'max:150'],
            'title.*' => ['nullable', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'array:'.$languages],
            'description.*' => ['nullable', 'string', 'max:8000'],
            'unit_id' => ['sometimes', 'string', 'size:26'],
            'position_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'employment_type' => ['sometimes', 'nullable', 'string', 'max:30'],
            'vacancies' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'salary_min_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000000'],
            'salary_max_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000000'],
            'show_salary' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'closes_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
