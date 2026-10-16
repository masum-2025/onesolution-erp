<?php

namespace Modules\FactoryErp\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Materials to the floor for an order (issue) or back from it (return):
 * the store, the day (today when left out, never later), items in
 * thousandths of their unit, a note; allow_over says yes to more than the
 * order needs where the company only warns.
 */
class IssueRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:issue,return'],
            'warehouse_id' => ['required', 'string', 'size:26'],
            'issued_on' => ['sometimes', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'string', 'size:26'],
            'lines.*.quantity_milli' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
            'allow_over' => ['sometimes', 'boolean'],
            'op_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
