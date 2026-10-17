<?php

namespace Modules\FactoryErp\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * An operator's piece ticket: the employee, the order and one operation
 * of its style, the day (today when left out, never later), the pieces.
 */
class PieceTicketRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'string', 'size:26'],
            'order_id' => ['required', 'string', 'size:26'],
            'operation_id' => ['required', 'string', 'size:26'],
            'worked_on' => ['sometimes', 'date_format:Y-m-d'],
            'pieces' => ['required', 'integer', 'min:1', 'max:100000'],
            'op_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
