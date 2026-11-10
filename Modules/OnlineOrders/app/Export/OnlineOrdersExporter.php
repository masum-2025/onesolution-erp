<?php

namespace Modules\OnlineOrders\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Modules\OnlineOrders\Models\Order;
use Modules\OnlineOrders\Models\OrderEvent;
use Modules\OnlineOrders\Models\OrderLine;
use Modules\OnlineOrders\Models\CourierPayout;
use Modules\OnlineOrders\Models\OrderPayment;

/** Online orders in the client's data export: orders with their lines, payments and history. */
class OnlineOrdersExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'online_orders';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        return [
            'orders' => $this->rows(Order::class, $organization, $organizationIds, fn (Order $row) => [
                'id' => $row->getKey(), 'number' => $row->number, 'status' => $row->status, 'channel' => $row->channel, 'unit_id' => $row->unit_id, 'warehouse_id' => $row->warehouse_id,
                'customer_name' => $row->customer_name, 'customer_phone' => $row->customer_phone, 'customer_id' => $row->customer_id, 'address' => $row->address,
                'delivery_zone' => $row->delivery_zone, 'payment_method' => $row->payment_method, 'subtotal_minor' => $row->subtotal_minor, 'discount_minor' => $row->discount_minor,
                'tax_minor' => $row->tax_minor, 'delivery_minor' => $row->delivery_minor, 'total_minor' => $row->total_minor, 'paid_minor' => $row->paid_minor,
                'refunded_minor' => $row->refunded_minor, 'cost_minor' => $row->cost_minor, 'currency_code' => $row->currency_code, 'created_at' => $row->created_at?->toIso8601String(),
                'delivered_at' => $row->delivered_at?->toIso8601String(), 'closed_at' => $row->closed_at?->toIso8601String(),
            ]),
            'order_lines' => $this->rows(OrderLine::class, $organization, $organizationIds, fn (OrderLine $row) => [
                'order_id' => $row->order_id, 'line_no' => $row->line_no, 'item_id' => $row->item_id, 'sku' => $row->sku, 'name' => json_encode($row->texts('name'), JSON_UNESCAPED_UNICODE),
                'quantity_milli' => $row->quantity_milli, 'unit_price_minor' => $row->unit_price_minor, 'discount_minor' => $row->discount_minor,
                'tax_minor' => $row->tax_minor, 'total_minor' => $row->total_minor, 'cost_minor' => $row->cost_minor,
            ]),
            'order_payments' => $this->rows(OrderPayment::class, $organization, $organizationIds, fn (OrderPayment $row) => [
                'order_id' => $row->order_id, 'kind' => $row->kind, 'method' => $row->method, 'amount_minor' => $row->amount_minor, 'reference' => $row->reference,
                'courier_payout_id' => $row->courier_payout_id, 'created_at' => $row->created_at->toIso8601String(),
            ]),
            // SC-6: payouts couriers sent for the cash they collected.
            'courier_payouts' => $this->rows(CourierPayout::class, $organization, $organizationIds, fn (CourierPayout $row) => [
                'id' => $row->getKey(), 'courier_id' => $row->courier_id, 'method' => $row->method, 'currency_code' => $row->currency_code,
                'collected_minor' => $row->collected_minor, 'received_minor' => $row->received_minor, 'fee_minor' => $row->fee_minor,
                'difference_minor' => $row->difference_minor, 'payments_count' => $row->payments_count, 'paid_on' => $row->paid_on->toDateString(),
                'reference' => $row->reference, 'reason' => $row->reason, 'created_at' => $row->created_at?->toIso8601String(),
            ]),
            'order_history' => $this->rows(OrderEvent::class, $organization, $organizationIds, fn (OrderEvent $row) => [
                'order_id' => $row->order_id, 'from_status' => $row->from_status, 'to_status' => $row->to_status, 'note' => $row->note, 'at' => $row->created_at->toIso8601String(),
            ]),
        ];
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @param  list<string>  $organizationIds
     * @param  callable(T): array<string, scalar|null>  $row
     * @return iterable<array<string, scalar|null>>
     */
    private function rows(string $model, Organization $organization, array $organizationIds, callable $row): iterable
    {
        foreach (array_chunk($organizationIds, 500) as $chunk) {
            foreach ($model::inTenantOf($organization)->withoutGlobalScope(OrganizationScope::class)->whereIn('organization_id', $chunk)->orderBy('id')->lazy(500) as $record) {
                yield $row($record);
            }
        }
    }
}
