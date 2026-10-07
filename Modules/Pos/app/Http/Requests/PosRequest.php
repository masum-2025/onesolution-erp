<?php

namespace Modules\Pos\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Pos\Models\Register;

/**
 * Point of sale input, by what the address names: a counter (created or
 * changed with the version seen), opening or closing a shift, reviewing
 * one, a sale, or a return.
 */
class PosRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $path = $this->path();
        $methods = 'in:'.implode(',', Register::METHODS);
        $payments = [
            'payments' => ['array', 'min:1', 'max:5'],
            'payments.*' => ['array:method,amount_minor,reference'],
            'payments.*.method' => ['required', $methods],
            'payments.*.amount_minor' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'payments.*.reference' => ['nullable', 'string', 'max:80'],
        ];

        if (str_contains($path, '/registers') && ! str_ends_with($path, '/open')) {
            $creating = $this->isMethod('post');
            $required = $creating ? 'required' : 'sometimes';

            return [
                'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
                'code' => [$required, 'string', 'regex:/^[A-Za-z0-9\-]{1,12}$/'],
                'name' => [$required, 'array:'.implode(',', (array) config('tenancy.supported_locales'))],
                'name.en' => [$required, 'string', 'min:1', 'max:80'],
                'name.*' => ['nullable', 'string', 'max:80'],
                'unit_id' => [$required, 'string', 'max:26'],
                'warehouse_id' => [$required, 'string', 'max:26'],
                'payment_methods' => [$required, 'array', 'min:1', 'max:3'],
                'payment_methods.*' => [$methods, 'distinct'],
                'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
            ];
        }
        if (str_ends_with($path, '/open')) {
            return ['opening_float_minor' => ['required', 'integer', 'min:0', 'max:999999999999']];
        }
        if (str_ends_with($path, '/close')) {
            return ['base_version' => ['required', 'integer', 'min:1'], 'counted_cash_minor' => ['required', 'integer', 'min:0', 'max:999999999999'], 'note' => ['nullable', 'string', 'max:500']];
        }
        if (str_ends_with($path, '/review')) {
            return ['base_version' => ['required', 'integer', 'min:1'], 'note' => ['required', 'string', 'min:5', 'max:500']];
        }
        if (str_ends_with($path, '/return')) {
            return [
                'op_id' => ['required', 'string', 'max:64'],
                'register_id' => ['required', 'string', 'max:26'],
                'reason' => ['required', 'string', 'min:3', 'max:300'],
                'lines' => ['required', 'array', 'min:1', 'max:200'],
                'lines.*' => ['array:line_id,quantity_milli'],
                'lines.*.line_id' => ['required', 'string', 'max:26'],
                'lines.*.quantity_milli' => ['required', 'integer', 'min:1', 'max:999999999999'],
                ...array_map(fn ($rules) => $rules === ['array', 'min:1', 'max:5'] ? ['sometimes', ...$rules] : $rules, $payments),
            ];
        }

        return [
            'op_id' => ['required', 'string', 'max:64'],
            'register_id' => ['required', 'string', 'max:26'],
            'customer_name' => ['nullable', 'string', 'max:150'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['array:item_id,quantity_milli,discount_minor'],
            'lines.*.item_id' => ['required', 'string', 'max:26'],
            'lines.*.quantity_milli' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'lines.*.discount_minor' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            ...array_map(fn ($rules) => $rules === ['array', 'min:1', 'max:5'] ? ['required', ...$rules] : $rules, $payments),
        ];
    }
}
