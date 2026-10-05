<?php

namespace Modules\Inventory\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Opening a stock count (warehouse, optional category, day), recording what
 * was counted (the version seen), or a step (a reason to reject).
 */
class CountRequest extends StrictFormRequest
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
        if ($this->isMethod('patch')) {
            return [
                'base_version' => ['required', 'integer', 'min:1'],
                'lines' => ['required', 'array', 'min:1', 'max:2000'],
                'lines.*' => ['array:item_id,counted_milli'],
                'lines.*.item_id' => ['required', 'string', 'max:26'],
                'lines.*.counted_milli' => ['present', 'nullable', 'integer', 'min:0', 'max:999999999999'],
            ];
        }

        return [
            'warehouse_id' => ['required', 'string', 'max:26'],
            'category_id' => ['nullable', 'string', 'max:26'],
            'counted_on' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
