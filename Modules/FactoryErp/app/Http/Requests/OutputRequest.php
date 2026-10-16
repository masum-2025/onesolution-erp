<?php

namespace Modules\FactoryErp\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * What a line sewed for an order: the day (today when left out), the hour
 * (0-23, where the company writes by the hour), good pieces and rejects
 * per size and colour.
 */
class OutputRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::fields();
    }

    /**
     * The same checks for an entry that comes later from an offline phone.
     *
     * @return array<string, mixed>
     */
    public static function fields(): array
    {
        return [
            'line_id' => ['required', 'string', 'size:26'],
            'order_id' => ['required', 'string', 'size:26'],
            'worked_on' => ['sometimes', 'date_format:Y-m-d'],
            'hour' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:23'],
            'lines' => ['required', 'array', 'min:1', 'max:1200'],
            'lines.*.size' => ['required', 'string', 'max:20'],
            'lines.*.color' => ['required', 'string', 'max:40'],
            'lines.*.good' => ['required', 'integer', 'min:0', 'max:100000'],
            'lines.*.rejects' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'op_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
