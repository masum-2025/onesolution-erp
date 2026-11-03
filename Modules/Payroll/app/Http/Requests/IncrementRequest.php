<?php

namespace Modules\Payroll\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;

/**
 * Opening an increment round (title in each language, the day it is from,
 * optionally the appraisal cycle that suggests the rates), a step of it
 * (the version seen; a reason to reject), or one line (left out; a rate in
 * basis points or a new basic set by hand with a reason; both null puts it
 * back on the rule).
 */
class IncrementRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $step = $this->route('step');
        if ($step !== null) {
            return [
                'base_version' => ['required', 'integer', 'min:1'],
                'reason' => [$step === 'reject' ? 'required' : 'prohibited', 'string', 'min:5', 'max:500'],
            ];
        }
        if ($this->route('line') !== null) {
            return [
                'excluded' => ['sometimes', 'boolean'],
                'rate_bp' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
                'new_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999999'],
                'reason' => ['sometimes', 'nullable', 'string', 'min:3', 'max:300'],
            ];
        }
        $locales = LanguageRegistry::codes();

        return [
            'title' => ['required', 'array:'.implode(',', $locales)],
            'title.en' => ['required', 'string', 'min:2', 'max:80'],
            'title.*' => ['nullable', 'string', 'max:80'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'cycle_id' => ['nullable', 'string', 'size:26'],
        ];
    }
}
