<?php

namespace Modules\Payroll\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;

/**
 * Opening a festival bonus (title in each language, the day it is for, a
 * share of the basic in basis points or the rule's), a step of it (the
 * version seen; a reason to reject; the day it was paid), or one line
 * (left out, an amount set by hand or null to work it out).
 */
class BonusRequest extends StrictFormRequest
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
                'paid_on' => [$step === 'pay' ? 'required' : 'prohibited', 'date_format:Y-m-d'],
            ];
        }
        if ($this->route('line') !== null) {
            return [
                'excluded' => ['sometimes', 'boolean'],
                'override_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999999'],
            ];
        }
        $locales = LanguageRegistry::codes();

        return [
            'title' => ['required', 'array:'.implode(',', $locales)],
            'title.en' => ['required', 'string', 'min:2', 'max:80'],
            'title.*' => ['nullable', 'string', 'max:80'],
            'bonus_on' => ['required', 'date_format:Y-m-d'],
            'rate_bp' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
