<?php

namespace Modules\Appraisals\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;

/** A review cycle: its name per language, the unit, the period and the last day of each step; the version seen when changing. */
class CycleRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $need = $creating ? 'required' : 'sometimes';

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'name' => [$need, 'array:'.implode(',', LanguageRegistry::codes())],
            'name.en' => [$creating ? 'required' : 'required_with:name', 'string', 'min:2', 'max:120'],
            'name.*' => ['nullable', 'string', 'max:120'],
            'unit_id' => ['sometimes', 'string', 'size:26'],
            'period_from' => [$need, 'date_format:Y-m-d'],
            'period_to' => [$need, 'date_format:Y-m-d'],
            'goals_due' => [$need, 'date_format:Y-m-d'],
            'self_due' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'manager_due' => [$need, 'date_format:Y-m-d'],
        ];
    }
}
