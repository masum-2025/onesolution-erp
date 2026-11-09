<?php

namespace Modules\OnlineOrders\Http;

use Illuminate\Support\Collection;
use Modules\OnlineOrders\Models\Order;
use Modules\OnlineOrders\Models\OrderEvent;
use Modules\OnlineOrders\Models\OrderLine;
use Modules\OnlineOrders\Models\OrderPayment;

/** API shapes of online orders (money in minor units, quantities in thousandths). */
class OrdersPresenter
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function order(Order $order, ?Collection $lines = null, ?Collection $payments = null, ?Collection $events = null, array $extra = []): array
    {
        return [
            'id' => $order->getKey(), 'number' => $order->number, 'status' => $order->status, 'channel' => $order->channel, 'unit_id' => $order->unit_id, 'warehouse_id' => $order->warehouse_id,
            'customer_name' => $order->customer_name, 'customer_phone' => $order->customer_phone, 'customer_id' => $order->customer_id, 'address' => $order->address,
            'delivery_zone' => $order->delivery_zone, 'payment_method' => $order->payment_method, 'note' => $order->note,
            'subtotal_minor' => $order->subtotal_minor, 'discount_minor' => $order->discount_minor, 'tax_minor' => $order->tax_minor, 'delivery_minor' => $order->delivery_minor,
            'total_minor' => $order->total_minor, 'paid_minor' => $order->paid_minor, 'refunded_minor' => $order->refunded_minor, 'due_minor' => $order->dueMinor(),
            'currency' => $order->currency_code, 'prices_include_tax' => $order->prices_include_tax,
            'courier' => $order->courier, 'tracking' => $order->tracking, 'cancel_reason' => $order->cancel_reason,
            'created_at' => $order->created_at?->toIso8601String(), 'confirmed_at' => $order->confirmed_at?->toIso8601String(), 'shipped_at' => $order->shipped_at?->toIso8601String(),
            'delivered_at' => $order->delivered_at?->toIso8601String(), 'closed_at' => $order->closed_at?->toIso8601String(), 'version' => $order->version,
            ...($lines === null ? [] : ['lines' => $lines->map(fn (OrderLine $line) => [
                'id' => $line->getKey(), 'line_no' => $line->line_no, 'item_id' => $line->item_id, 'sku' => $line->sku, 'name' => $line->name,
                'quantity_milli' => $line->quantity_milli, 'unit_price_minor' => $line->unit_price_minor, 'discount_minor' => $line->discount_minor,
                'tax_rate_bp' => $line->tax_rate_bp, 'tax_minor' => $line->tax_minor, 'total_minor' => $line->total_minor,
            ])->values()->all()]),
            ...($payments === null ? [] : ['payments' => $payments->map(fn (OrderPayment $payment) => [
                'id' => $payment->getKey(), 'kind' => $payment->kind, 'method' => $payment->method, 'amount_minor' => $payment->amount_minor,
                'reference' => $payment->reference, 'created_at' => $payment->created_at->toIso8601String(),
            ])->values()->all()]),
            ...($events === null ? [] : ['history' => $events->map(fn (OrderEvent $event) => [
                'from' => $event->from_status, 'to' => $event->to_status, 'note' => $event->note, 'at' => $event->created_at->toIso8601String(),
            ])->values()->all()]),
            ...$extra,
        ];
    }
}
