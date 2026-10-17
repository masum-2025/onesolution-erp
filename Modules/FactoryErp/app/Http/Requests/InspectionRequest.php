<?php

namespace Modules\FactoryErp\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * An end-line check (a line, pieces checked, defects by kind) or an
 * order's final inspection (the lot; the sample and the most defects
 * accepted come from the AQL table), on a day (today when left out).
 */
class InspectionRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $final = $this->input('kind') === 'final';

        return [
            'kind' => ['required', 'in:endline,final'],
            'order_id' => ['required', 'string', 'size:26'],
            'line_id' => [$final ? 'prohibited' : 'required', 'string', 'size:26'],
            'inspected_on' => ['sometimes', 'date_format:Y-m-d'],
            'hour' => [$final ? 'prohibited' : 'sometimes', 'nullable', 'integer', 'min:0', 'max:23'],
            'checked' => [$final ? 'prohibited' : 'required', 'integer', 'min:1', 'max:100000'],
            'lot_size' => [$final ? 'required' : 'prohibited', 'integer', 'min:2', 'max:10000000'],
            'defects' => ['sometimes', 'array', 'max:100'],
            'defects.*.defect_type_id' => ['required', 'string', 'size:26'],
            'defects.*.count' => ['required', 'integer', 'min:0', 'max:100000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
            'op_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
