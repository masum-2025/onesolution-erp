<?php

namespace Modules\Inventory\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Inventory\Models\Document;

/**
 * An inventory document (type, warehouses, day, lines), changed while a
 * draft (the version seen), or a step of it (the version seen; a reason to
 * reject; what arrived for a transfer; the supplier for its bill).
 */
class DocumentRequest extends StrictFormRequest
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
                'received' => [$step === 'receive' ? 'sometimes' : 'prohibited', 'array', 'max:500'],
                'received.*' => ['integer', 'min:0', 'max:999999999999'],
                'party_id' => [$step === 'bill' ? 'required' : 'prohibited', 'string', 'size:26'],
                'issue_date' => [$step === 'bill' ? 'sometimes' : 'prohibited', 'nullable', 'date_format:Y-m-d'],
            ];
        }
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'type' => [$creating ? 'required' : 'prohibited', 'in:'.implode(',', Document::TYPES)],
            'warehouse_id' => [$required, 'string', 'max:26'],
            'to_warehouse_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'document_date' => [$required, 'date_format:Y-m-d'],
            'counterparty' => ['sometimes', 'nullable', 'string', 'max:150'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:80'],
            'reason' => [$this->input('type') === 'adjustment' ? $required : 'sometimes', 'nullable', 'string', 'min:3', 'max:300'],
            'op_id' => [$creating ? 'sometimes' : 'prohibited', 'nullable', 'string', 'max:64'],
            'lines' => [$required, 'array', 'min:1', 'max:500'],
            'lines.*' => ['array:item_id,quantity_milli,unit_cost_minor,batch_number,expires_on,note'],
            'lines.*.item_id' => ['required', 'string', 'max:26'],
            'lines.*.quantity_milli' => ['required', 'integer', 'between:-999999999999,999999999999'],
            'lines.*.unit_cost_minor' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'lines.*.batch_number' => ['nullable', 'string', 'max:60'],
            'lines.*.expires_on' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
