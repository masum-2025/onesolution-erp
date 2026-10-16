<?php

namespace Modules\FactoryErp\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A style's whole operation bulletin: each sewing job's name (once),
 * machine, standard minutes in thousandths of a minute, and the piece
 * rate an operator is paid for it (minor units; optional).
 */
class OperationsRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'lines' => ['present', 'array', 'max:150'],
            'lines.*.name' => ['required', 'string', 'min:2', 'max:120'],
            'lines.*.machine' => ['nullable', 'string', 'max:40'],
            'lines.*.smv_milli' => ['required', 'integer', 'min:1', 'max:600000'],
            'lines.*.piece_rate_minor' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ];
    }
}
