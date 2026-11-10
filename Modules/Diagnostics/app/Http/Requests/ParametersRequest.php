<?php

namespace Modules\Diagnostics\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;
use Modules\Diagnostics\Models\NormalRange;
use Modules\Diagnostics\Models\Parameter;

/**
 * A test's parameters with their normal ranges, sent together. Limits are
 * decimals as text ("12.5"), never floats; an age band is in days.
 */
class ParametersRequest extends StrictFormRequest
{
    private const DECIMAL = 'regex:/^-?\d{1,10}(\.\d{1,4})?$/';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $locales = implode(',', LanguageRegistry::codes());

        return [
            'parameters' => ['present', 'array', 'max:60'],
            'parameters.*.id' => ['nullable', 'string', 'size:26'],
            'parameters.*.name' => ['required', 'array:'.$locales],
            'parameters.*.name.en' => ['required', 'string', 'max:100'],
            'parameters.*.name.*' => ['nullable', 'string', 'max:100'],
            'parameters.*.unit' => ['nullable', 'string', 'max:30'],
            'parameters.*.result_type' => ['required', 'in:'.implode(',', Parameter::TYPES)],
            'parameters.*.choices' => ['nullable', 'required_if:parameters.*.result_type,choice', 'array', 'max:20'],
            'parameters.*.choices.*' => ['required', 'string', 'max:50'],
            'parameters.*.decimals' => ['nullable', 'integer', 'min:0', 'max:4'],
            'parameters.*.ranges' => ['nullable', 'array', 'max:20'],
            'parameters.*.ranges.*.sex' => ['required', 'in:'.implode(',', NormalRange::SEXES)],
            'parameters.*.ranges.*.age_from_days' => ['nullable', 'integer', 'min:0', 'max:60000'],
            'parameters.*.ranges.*.age_to_days' => ['nullable', 'integer', 'min:0', 'max:60000'],
            'parameters.*.ranges.*.low' => ['nullable', 'string', self::DECIMAL],
            'parameters.*.ranges.*.high' => ['nullable', 'string', self::DECIMAL],
            'parameters.*.ranges.*.critical_low' => ['nullable', 'string', self::DECIMAL],
            'parameters.*.ranges.*.critical_high' => ['nullable', 'string', self::DECIMAL],
            'parameters.*.ranges.*.normal_text' => ['nullable', 'string', 'max:150'],
        ];
    }
}
