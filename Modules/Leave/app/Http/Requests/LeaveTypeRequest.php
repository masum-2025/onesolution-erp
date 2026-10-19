<?php

namespace Modules\Leave\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;
use Modules\Leave\Models\LeaveType;

/**
 * A kind of leave: code, name in every supported language, and how it
 * works (amounts in half days: 20 = ten days). Changing one needs the
 * version seen (base_version).
 */
class LeaveTypeRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';
        $locales = LanguageRegistry::codes();

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'code' => [$required, 'string', 'regex:/^[A-Za-z][A-Za-z0-9_-]{0,19}$/'],
            'name' => [$required, 'array:'.implode(',', $locales)],
            'name.en' => [$required, 'string', 'min:2', 'max:80'],
            'name.*' => ['nullable', 'string', 'max:80'],
            'paid' => [$required, 'boolean'],
            'accrual' => [$required, 'string', 'in:'.implode(',', LeaveType::ACCRUALS)],
            'allowance_halves' => ['sometimes', 'integer', 'min:0', 'max:730'],
            'worked_days_per_day' => ['nullable', 'required_if:accrual,worked_days', 'integer', 'min:1', 'max:365'],
            'carry_max_halves' => ['sometimes', 'integer', 'min:0', 'max:730'],
            'half_day' => ['sometimes', 'boolean'],
            'counts_days_off' => ['sometimes', 'boolean'],
            'document_after_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'gender' => ['nullable', 'string', 'in:female,male'],
            'encashable' => ['sometimes', 'boolean'],
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
