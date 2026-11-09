<?php

namespace Modules\OnlineOrders\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\OnlineOrders\Models\Order;
use Modules\OnlineOrders\Models\OrderPayment;

/**
 * Online order input, by what the address names: a new order or a draft
 * changed (with the version seen), a step (confirm, pack, ship, deliver),
 * calling it off or taking it back, a payment.
 */
class OrderRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $path = $this->path();
        $methods = 'in:'.implode(',', OrderPayment::METHODS);
        $version = ['base_version' => ['required', 'integer', 'min:1']];

        if (str_ends_with($path, '/payments')) {
            return [
                'op_id' => ['required', 'string', 'max:64'],
                'method' => ['required', $methods],
                'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999'],
                'reference' => ['nullable', 'string', 'max:80'],
            ];
        }
        if (preg_match('#/(confirm|pack|deliver)$#', $path)) {
            return $version;
        }
        if (str_ends_with($path, '/ship')) {
            return [...$version, 'courier' => ['nullable', 'string', 'max:80'], 'tracking' => ['nullable', 'string', 'max:80']];
        }
        if (preg_match('#/(cancel|return)$#', $path)) {
            return [...$version, 'reason' => ['required', 'string', 'min:3', 'max:300'], 'refund_method' => ['nullable', $methods]];
        }

        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'op_id' => [$creating ? 'required' : 'prohibited', 'string', 'max:64'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'warehouse_id' => [$required, 'string', 'max:26'],
            'customer_name' => [$required, 'string', 'min:2', 'max:150'],
            'customer_phone' => [$required, 'string', 'max:20'],
            'address' => [$required, 'string', 'min:5', 'max:500'],
            'payment_method' => [$required, 'in:'.implode(',', Order::PAYMENT_METHODS)],
            'delivery_zone' => ['nullable', 'string', 'regex:/^[a-z0-9_]{1,20}$/'],
            'delivery_minor' => ['nullable', 'integer', 'min:0', 'max:999999999', 'prohibits:delivery_zone'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => [$required, 'array', 'min:1', 'max:100'],
            'lines.*' => ['array:item_id,quantity_milli,discount_minor'],
            'lines.*.item_id' => ['required', 'string', 'max:26', 'distinct'],
            'lines.*.quantity_milli' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'lines.*.discount_minor' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ];
    }
}
