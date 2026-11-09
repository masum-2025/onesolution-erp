<?php

namespace Modules\OnlineOrders\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Support\PhoneNumber;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Payments\PaymentCollectables;
use App\Platform\Payments\Services\MerchantAccounts;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Support\MoneyText;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\Accounting\Services\TaxCodes;
use Modules\Crm\Services\Customers;
use Modules\Inventory\Services\Stock;
use Modules\OnlineOrders\Events\OrderCancelled;
use Modules\OnlineOrders\Events\OrderConfirmed;
use Modules\OnlineOrders\Events\OrderDelivered;
use Modules\OnlineOrders\Events\OrderPaid;
use Modules\OnlineOrders\Payments\OrderCollectable;
use Modules\OnlineOrders\Events\OrderShipped;
use Modules\OnlineOrders\Exceptions\OnlineOrdersException;
use Modules\OnlineOrders\Models\Order;
use Modules\OnlineOrders\Models\OrderEvent;
use Modules\OnlineOrders\Models\OrderLine;
use Modules\OnlineOrders\Models\OrderPayment;
use Modules\OnlineOrders\Models\Sequence;

/**
 * Online orders, the public service other modules (Facebook selling, the
 * website shop) place orders through.
 *
 * An order is made as a draft at a branch, shipping out of one warehouse:
 * items at Inventory's price, a discount per line (above rule
 * online_orders.max_discount_percent of the goods only for
 * online_orders.supervise), tax from the item's tax code, the delivery
 * charge of its zone (rule online_orders.delivery_zones), cash on delivery
 * where rule online_orders.cod_allowed says so. Prices are frozen as the
 * draft is made or changed.
 *
 * Confirmed, it holds its stock: other orders see that much less in the
 * warehouse. Shipped, stock leaves at its cost. Delivered, it is posted
 * where the company keeps books (what the customer owes against sales, tax
 * and delivery income; the cost from inventory.stock to inventory.cogs) and
 * counted in the customer's purchases (CRM). Payments and refunds are
 * append-only, each posted against what the customer owes.
 *
 * Cancelled before shipping it lets its stock go; once shipped (it came
 * back) or delivered (taken back within rule online_orders.return_days)
 * only online_orders.supervise may, and the stock comes back at what it
 * left at. Every change carries the version seen; making an order or a
 * payment carries an op id, so sending it again changes nothing.
 */
class Orders
{
    public function __construct(
        private OrderBook $book,
        private Stock $stock,
        private OrderPostings $postings,
        private ModuleResolver $modules,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * A new draft at a branch of the company.
     *
     * @param  array{op_id: string, warehouse_id: string, customer_name: string, customer_phone: string, address: string, payment_method: string, delivery_zone?: string|null, delivery_minor?: int|null, note?: string|null, lines: list<array{item_id: string, quantity_milli: int, discount_minor?: int}>}  $data
     */
    public function place(Organization $company, Organization $branch, array $data, ?User $actor, string $channel = 'manual', ?string $channelRef = null): Order
    {
        $existing = $this->book->query(Order::class, $company)->where('op_id', $data['op_id'])->first();
        if ($existing !== null) {
            return $existing;
        }
        $this->warehouse($company, $data['warehouse_id']);
        $priced = $this->price($company, $branch, $data, $actor);
        $customer = $this->customer($company, $branch, $data, $actor);

        return $this->book->transaction($company, function () use ($company, $branch, $data, $actor, $channel, $channelRef, $priced, $customer) {
            $order = new Order;
            $order->fill([
                'organization_id' => $company->getKey(), 'unit_id' => $branch->getKey(), 'warehouse_id' => $data['warehouse_id'],
                'number' => $this->number($company, CarbonImmutable::now()), 'channel' => $channel, 'channel_ref' => $channelRef, 'status' => Order::DRAFT,
                ...$customer, ...$this->totals($priced), 'currency_code' => $this->book->currency($company),
                'payment_method' => $data['payment_method'], 'note' => $data['note'] ?? null, 'created_by' => $actor?->getKey(), 'op_id' => $data['op_id'], 'version' => 1,
            ])->save();
            $this->writeLines($company, $order, $priced);
            $this->history($company, $order, null, Order::DRAFT, null, $actor);
            $this->audit->record('online_orders.order_placed', $order, new: ['number' => $order->number, 'channel' => $channel, 'total_minor' => $order->total_minor],
                actor: $actor, organizationId: $company->getKey());

            return $order;
        });
    }

    /**
     * A draft changed: customer, address, delivery, payment and its lines
     * (the lines given replace the old ones and are priced again).
     *
     * @param  array<string, mixed>  $data
     */
    public function revise(Organization $company, Order $order, int $baseVersion, array $data, User $actor): Order
    {
        $this->expect($order, 'revise', [Order::DRAFT]);
        $branch = Organization::query()->findOrFail($order->unit_id);
        $merged = [
            'warehouse_id' => $order->warehouse_id, 'customer_name' => $order->customer_name, 'customer_phone' => $order->customer_phone, 'address' => $order->address,
            'payment_method' => $order->payment_method, 'delivery_zone' => $order->delivery_zone, 'note' => $order->note, ...$data,
        ];
        if (! array_key_exists('delivery_zone', $data) && ! array_key_exists('delivery_minor', $data) && $order->delivery_zone === null) {
            $merged['delivery_minor'] = $order->delivery_minor;
        }
        $merged['lines'] ??= $this->book->query(OrderLine::class, $company)->where('order_id', $order->getKey())->orderBy('line_no')->get()
            ->map(fn (OrderLine $line) => ['item_id' => $line->item_id, 'quantity_milli' => $line->quantity_milli, 'discount_minor' => $line->discount_minor])->all();
        $this->warehouse($company, $merged['warehouse_id']);
        $priced = $this->price($company, $branch, $merged, $actor);
        $customer = $this->customer($company, $branch, $merged, $actor);

        return $this->change($company, $order, $baseVersion, function (Order $row) use ($company, $merged, $priced, $customer, $actor) {
            $old = $row->only(['customer_name', 'address', 'delivery_zone', 'payment_method', 'total_minor']);
            $row->forceFill([...$customer, ...$this->totals($priced), 'warehouse_id' => $merged['warehouse_id'], 'payment_method' => $merged['payment_method'], 'note' => $merged['note'] ?? null]);
            $this->book->query(OrderLine::class, $company)->where('order_id', $row->getKey())->delete();
            $this->writeLines($company, $row, $priced);
            $this->audit->record('online_orders.order_changed', $row, old: $old, new: $row->only(array_keys($old)), actor: $actor, organizationId: $company->getKey());
        });
    }

    /** A draft confirmed: its stock is held, if the warehouse has it beyond what other orders hold. */
    public function confirm(Organization $company, Order $order, int $baseVersion, User $actor): Order
    {
        $this->expect($order, 'confirm', [Order::DRAFT]);
        $confirmed = $this->change($company, $order, $baseVersion, function (Order $row) use ($company, $actor) {
            $lines = $this->book->query(OrderLine::class, $company)->where('order_id', $row->getKey())->where('keeps_stock', true)->get();
            $wanted = $lines->groupBy('item_id')->map(fn ($group) => (int) $group->sum('quantity_milli'))->all();
            $available = $this->available($company, $row->warehouse_id, array_keys($wanted), $row->getKey());
            foreach ($wanted as $itemId => $quantity) {
                if ($quantity > ($available[$itemId] ?? 0)) {
                    throw OnlineOrdersException::notEnoughStock($lines->firstWhere('item_id', $itemId)->sku, self::quantity(max(0, $available[$itemId] ?? 0)));
                }
            }
            $this->move($company, $row, Order::CONFIRMED, $actor, ['confirmed_at' => CarbonImmutable::now()]);
        });
        OrderConfirmed::dispatch($company->getKey(), $confirmed->getKey(), $confirmed->channel, $confirmed->channel_ref, $confirmed->total_minor, $confirmed->currency_code);

        return $confirmed;
    }

    public function pack(Organization $company, Order $order, int $baseVersion, User $actor): Order
    {
        $this->expect($order, 'pack', [Order::CONFIRMED]);

        return $this->change($company, $order, $baseVersion, fn (Order $row) => $this->move($company, $row, Order::PACKED, $actor));
    }

    /**
     * Handed to the courier: stock leaves the warehouse at its cost. An order
     * paid before sending (prepaid) goes only once paid in full.
     *
     * @param  array{courier?: string|null, tracking?: string|null}  $data
     */
    public function ship(Organization $company, Order $order, int $baseVersion, array $data, User $actor): Order
    {
        $this->expect($order, 'ship', [Order::CONFIRMED, Order::PACKED]);
        if ($order->payment_method === 'prepaid' && $order->dueMinor() > 0) {
            throw OnlineOrdersException::prepaidNotPaid($this->shown($order, $order->dueMinor()));
        }

        $shipped = $this->change($company, $order, $baseVersion, function (Order $row) use ($company, $data, $actor) {
            $lines = $this->book->query(OrderLine::class, $company)->where('order_id', $row->getKey())->orderBy('line_no')->get();
            $now = CarbonImmutable::now();
            $moved = $this->stock->take($company, $row->warehouse_id, $lines->map(fn (OrderLine $line) => ['item_id' => $line->item_id, 'quantity_milli' => $line->quantity_milli])->all(),
                ['module' => 'online_orders', 'type' => 'order', 'id' => $row->getKey(), 'actor_id' => $actor->getKey()], $now);
            foreach ($lines->values() as $index => $line) {
                $line->forceFill(['cost_minor' => $moved['lines'][$index] ?? 0])->save();
            }
            $this->move($company, $row, Order::SHIPPED, $actor, ['shipped_at' => $now, 'cost_minor' => $moved['cost_minor'],
                'courier' => $data['courier'] ?? null, 'tracking' => $data['tracking'] ?? null]);
        });
        OrderShipped::dispatch($company->getKey(), $shipped->getKey(), $shipped->channel, $shipped->channel_ref);

        return $shipped;
    }

    /**
     * What an order would cost, without keeping it (a channel shows the customer before they say yes):
     * the same pricing as place(), in the company's currency.
     *
     * @param  array<string, mixed>  $data  as for place() (warehouse_id, lines, payment_method, delivery_zone or delivery_minor)
     * @return array{subtotal_minor: int, discount_minor: int, tax_minor: int, delivery_minor: int, total_minor: int, prices_include_tax: bool, currency: string}
     */
    public function quote(Organization $company, Organization $branch, array $data): array
    {
        $this->warehouse($company, $data['warehouse_id']);
        $totals = $this->totals($this->price($company, $branch, $data, null));
        unset($totals['delivery_zone']);

        return [...$totals, 'currency' => $this->book->currency($company)];
    }

    /**
     * An order as a channel tells its customer about it (no personal data).
     *
     * @return array{id: string, number: string, status: string, total_minor: int, due_minor: int, currency: string, courier: ?string, tracking: ?string, channel: string, channel_ref: ?string}|null
     */
    public function brief(Organization $company, string $orderId): ?array
    {
        $order = $this->book->query(Order::class, $company)->whereKey($orderId)->first();

        return $order === null ? null : [
            'id' => $order->getKey(), 'number' => $order->number, 'status' => $order->status, 'total_minor' => $order->total_minor, 'due_minor' => $order->dueMinor(),
            'currency' => $order->currency_code, 'courier' => $order->courier, 'tracking' => $order->tracking, 'channel' => $order->channel, 'channel_ref' => $order->channel_ref,
        ];
    }

    /** It reached the customer: posted to the books and counted in their purchases. */
    public function deliver(Organization $company, Order $order, int $baseVersion, User $actor): Order
    {
        $this->expect($order, 'deliver', [Order::SHIPPED]);
        $delivered = $this->change($company, $order, $baseVersion, function (Order $row) use ($company, $actor) {
            $now = CarbonImmutable::now();
            $goods = $row->total_minor - $row->delivery_minor;
            $journal = $this->postings->post($company, "online-order-delivered-{$row->getKey()}", $now->startOfDay(), __('online_orders::orders.narration.delivered', ['number' => $row->number]),
                'order', $row->getKey(), $row->currency_code, [
                    LedgerLine::debit('online_orders.receivable', $row->total_minor, $row->unit_id),
                    LedgerLine::credit('online_orders.sales', $goods - $row->tax_minor, $row->unit_id),
                    LedgerLine::credit('online_orders.tax_output', $row->tax_minor),
                    LedgerLine::credit('online_orders.delivery_income', $row->delivery_minor, $row->unit_id),
                    LedgerLine::debit('inventory.cogs', $row->cost_minor, $row->unit_id),
                    LedgerLine::credit('inventory.stock', $row->cost_minor, $row->unit_id),
                ]);
            $this->move($company, $row, Order::DELIVERED, $actor, ['delivered_at' => $now, 'delivery_journal_id' => $journal]);
        });
        if ($delivered->customer_id !== null && $this->crmOn($company)) {
            app(Customers::class)->recordSale($company, $delivered->customer_id, 'online_orders', 'order', $delivered->getKey(), $delivered->total_minor - $delivered->delivery_minor,
                $delivered->delivered_at->toDateString(), $delivered->currency_code);
        }
        OrderDelivered::dispatch($company->getKey(), $delivered->getKey(), $delivered->channel, $delivered->channel_ref, $delivered->customer_id, $delivered->total_minor,
            $delivered->currency_code, $delivered->delivered_at->toDateString());

        return $delivered;
    }

    /**
     * Money in from the customer or the courier (cash on delivery): never
     * more than is owed; posted against what the customer owes.
     *
     * @param  array{op_id: string, method: string, amount_minor: int, reference?: string|null}  $data
     */
    public function pay(Organization $company, Order $order, array $data, User $actor): Order
    {
        $done = $this->book->query(OrderPayment::class, $company)->where('op_id', $data['op_id'])->first();
        if ($done !== null) {
            return $this->book->query(Order::class, $company)->whereKey($done->order_id)->firstOrFail();
        }
        $this->expect($order, 'pay', [Order::DRAFT, Order::CONFIRMED, Order::PACKED, Order::SHIPPED, Order::DELIVERED]);
        $amount = (int) $data['amount_minor'];
        if ($order->dueMinor() <= 0) {
            throw OnlineOrdersException::nothingDue();
        }
        if ($amount > $order->dueMinor()) {
            throw OnlineOrdersException::overpaid($this->shown($order, $order->dueMinor()));
        }

        return $this->book->transaction($company, function () use ($company, $order, $data, $amount, $actor) {
            $row = $this->book->query(Order::class, $company)->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($amount > $row->dueMinor()) {
                throw OnlineOrdersException::overpaid($this->shown($row, $row->dueMinor()));
            }
            $this->money($company, $row, 'payment', $data['method'], $amount, $data['reference'] ?? null, $data['op_id'], $actor);
            $row->forceFill(['paid_minor' => $row->paid_minor + $amount, 'version' => $row->version + 1])->save();

            return $row;
        });
    }

    /**
     * SC-5a: what the customer may pay online through a link now (rule
     * online_orders.online_payment at the order's branch: the delivery charge
     * or the whole order), less what is already paid; null when nothing is
     * (rule none, paid, or the order is past packing or called off).
     *
     * @return array{order: Order, amount_minor: int, currency: string}|null
     */
    public function onlineDue(Organization $company, string $orderId): ?array
    {
        $order = $this->book->query(Order::class, $company)->whereKey($orderId)->first();
        if ($order === null || ! in_array($order->status, [Order::DRAFT, Order::CONFIRMED, Order::PACKED], true)) {
            return null;
        }
        $branch = Organization::query()->find($order->unit_id) ?? $company;
        $target = match ((string) $this->rules->get('online_orders.online_payment', $this->contexts->forOrganization($branch))) {
            'full' => $order->total_minor,
            'delivery' => $order->delivery_minor,
            default => 0,
        };
        $amount = min($target - $order->paid_minor, $order->dueMinor());

        return $amount > 0 ? ['order' => $order, 'amount_minor' => $amount, 'currency' => $order->currency_code] : null;
    }

    /**
     * A link the customer opens without a sign-in to pay what onlineDue says
     * (signed, for online_orders.pay_link_hours); null when there is nothing
     * to pay or the company takes no online payments.
     *
     * @return array{url: string, amount_minor: int, currency: string, expires_at: string}|null
     */
    public function payLink(Organization $company, string $orderId, ?string $locale = null): ?array
    {
        if (! app(PaymentCollectables::class)->usable(OrderCollectable::KIND, $company)) {
            return null;
        }
        $due = $this->onlineDue($company, $orderId);
        if ($due === null || app(MerchantAccounts::class)->collectingAccount($company, $due['currency']) === null) {
            return null;
        }
        $expires = CarbonImmutable::now()->addHours((int) $this->rules->get('online_orders.pay_link_hours', $this->contexts->forOrganization($company)));

        return [
            // Signed without the host: the same link works on the partner's own domain.
            'url' => url(URL::temporarySignedRoute('online_orders.pay', $expires, ['organization' => $company->getKey(), 'order' => $orderId, ...($locale === null ? [] : ['lang' => $locale])], absolute: false)),
            'amount_minor' => $due['amount_minor'],
            'currency' => $due['currency'],
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    /**
     * The gateway confirmed money the customer paid through a link: always
     * kept (once per payment), even past what is owed now (the order changed
     * or was called off meanwhile: staff give the rest back). Nobody typed it.
     */
    public function payOnline(Organization $company, string $orderId, int $amount, string $reference, string $opId): void
    {
        if ($this->book->query(OrderPayment::class, $company)->where('op_id', $opId)->exists()) {
            return;
        }
        $row = $this->book->transaction($company, function () use ($company, $orderId, $amount, $reference, $opId) {
            $row = $this->book->query(Order::class, $company)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $this->money($company, $row, 'payment', OrderPayment::ONLINE, $amount, mb_substr($reference, 0, 80), $opId, null);
            $row->forceFill(['paid_minor' => $row->paid_minor + $amount, 'version' => $row->version + 1])->save();

            return $row;
        });
        OrderPaid::dispatch($company->getKey(), $row->getKey(), $row->channel, $row->channel_ref, $amount, $row->currency_code);
    }

    /**
     * Called off. Before shipping its stock is let go; shipped (it came
     * back undelivered) needs online_orders.supervise and the stock comes
     * back. Money kept is given back by the method named.
     *
     * @param  array{reason: string, refund_method?: string|null}  $data
     */
    public function cancel(Organization $company, Order $order, int $baseVersion, array $data, User $actor): Order
    {
        $this->expect($order, 'cancel', [Order::DRAFT, Order::CONFIRMED, Order::PACKED, Order::SHIPPED]);
        if ($order->status === Order::SHIPPED) {
            $this->supervisor($actor, $order);
        }
        $refund = $order->heldMinor();
        if ($refund > 0 && empty($data['refund_method'])) {
            throw OnlineOrdersException::refundMethodMissing($this->shown($order, $refund));
        }
        $cancelled = $this->change($company, $order, $baseVersion, function (Order $row) use ($company, $data, $actor, $refund) {
            if ($row->status === Order::SHIPPED) {
                $this->stockBack($company, $row, $actor, 'order_cancelled');
            }
            if ($refund > 0) {
                $this->money($company, $row, 'refund', $data['refund_method'], $refund, null, "online-order-refund-{$row->getKey()}", $actor);
                $row->refunded_minor += $refund;
            }
            $this->move($company, $row, Order::CANCELLED, $actor, ['cancel_reason' => $data['reason'], 'closed_at' => CarbonImmutable::now()], $data['reason']);
        });
        OrderCancelled::dispatch($company->getKey(), $cancelled->getKey(), $cancelled->channel, $cancelled->channel_ref, Order::CANCELLED);

        return $cancelled;
    }

    /**
     * A delivered order sent back within rule online_orders.return_days
     * (online_orders.supervise): the goods come back at what they left at,
     * their sale is reversed in the books and the customer's purchases; the
     * delivery charge stays owed, money kept beyond it is given back.
     *
     * @param  array{reason: string, refund_method?: string|null}  $data
     */
    public function takeBack(Organization $company, Order $order, int $baseVersion, array $data, User $actor): Order
    {
        $this->expect($order, 'return', [Order::DELIVERED]);
        $this->supervisor($actor, $order);
        $days = (int) $this->rules->get('online_orders.return_days', $this->contexts->forOrganization(Organization::query()->findOrFail($order->unit_id)));
        if ($order->delivered_at->addDays($days)->endOfDay()->isPast()) {
            throw OnlineOrdersException::returnTooLate($days);
        }
        $goods = $order->total_minor - $order->delivery_minor;
        $refund = max(0, $order->heldMinor() - $order->delivery_minor);
        if ($refund > 0 && empty($data['refund_method'])) {
            throw OnlineOrdersException::refundMethodMissing($this->shown($order, $refund));
        }
        $returned = $this->change($company, $order, $baseVersion, function (Order $row) use ($company, $data, $actor, $goods, $refund) {
            $now = CarbonImmutable::now();
            $this->stockBack($company, $row, $actor, 'order_returned');
            $journal = $this->postings->post($company, "online-order-returned-{$row->getKey()}", $now->startOfDay(), __('online_orders::orders.narration.returned', ['number' => $row->number]),
                'order_return', $row->getKey(), $row->currency_code, [
                    LedgerLine::debit('online_orders.sales', $goods - $row->tax_minor, $row->unit_id),
                    LedgerLine::debit('online_orders.tax_output', $row->tax_minor),
                    LedgerLine::credit('online_orders.receivable', $goods, $row->unit_id),
                    LedgerLine::debit('inventory.stock', $row->cost_minor, $row->unit_id),
                    LedgerLine::credit('inventory.cogs', $row->cost_minor, $row->unit_id),
                ]);
            if ($refund > 0) {
                $this->money($company, $row, 'refund', $data['refund_method'], $refund, null, "online-order-refund-{$row->getKey()}", $actor);
                $row->refunded_minor += $refund;
            }
            $this->move($company, $row, Order::RETURNED, $actor, ['cancel_reason' => $data['reason'], 'return_journal_id' => $journal, 'closed_at' => $now], $data['reason']);
        });
        if ($returned->customer_id !== null && $this->crmOn($company)) {
            app(Customers::class)->recordSale($company, $returned->customer_id, 'online_orders', 'order_return', $returned->getKey(), -$goods,
                CarbonImmutable::now()->toDateString(), $returned->currency_code);
        }
        OrderCancelled::dispatch($company->getKey(), $returned->getKey(), $returned->channel, $returned->channel_ref, Order::RETURNED);

        return $returned;
    }

    /**
     * Stock of items in a warehouse less what confirmed orders not yet
     * shipped hold (an order's own hold left out).
     *
     * @param  list<string>  $itemIds
     * @return array<string, int> item id => thousandths
     */
    public function available(Organization $company, string $warehouseId, array $itemIds, ?string $exceptOrderId = null): array
    {
        if ($itemIds === []) {
            return [];
        }
        $onHand = $this->stock->onHand($company, $warehouseId, $itemIds);
        $holding = $this->book->query(Order::class, $company)->where('warehouse_id', $warehouseId)->whereIn('status', Order::RESERVING)
            ->when($exceptOrderId !== null, fn ($query) => $query->whereKeyNot($exceptOrderId))->pluck('id')->all();
        $held = $holding === [] ? collect() : $this->book->query(OrderLine::class, $company)->whereIn('order_id', $holding)->whereIn('item_id', $itemIds)
            ->where('keeps_stock', true)->get(['item_id', 'quantity_milli'])->groupBy('item_id')->map(fn ($rows) => (int) $rows->sum('quantity_milli'));
        $out = [];
        foreach ($itemIds as $id) {
            $out[$id] = (int) ($onHand[$id] ?? 0) - (int) ($held[$id] ?? 0);
        }

        return $out;
    }

    /** Delivery zones of a branch (rule online_orders.delivery_zones). @return list<array{code: string, name: array<string, string>, charge_minor: int}> */
    public function zones(Organization $branch): array
    {
        return array_values((array) $this->rules->get('online_orders.delivery_zones', $this->contexts->forOrganization($branch)));
    }

    // ---------------------------------------------------------------- inside

    /** Money as the reader sees it (in a message that says what to do). */
    private function shown(Order $order, int $minor): string
    {
        return MoneyText::shown($minor, $order->currency_code, app()->getLocale());
    }

    /** @param  list<string>  $allowed */
    private function expect(Order $order, string $action, array $allowed): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw OnlineOrdersException::wrongStatus(__("online_orders::orders.actions.{$action}"), __("online_orders::orders.statuses.{$order->status}"));
        }
    }

    /** Thousandths as a plain number ("2.5"). */
    private static function quantity(int $milli): string
    {
        $text = rtrim(rtrim(sprintf('%d.%03d', intdiv($milli, 1000), $milli % 1000), '0'), '.');

        return $text === '' ? '0' : $text;
    }

    private function supervisor(User $actor, Order $order): void
    {
        if (! Gate::forUser($actor)->allows('online_orders.supervise', Organization::query()->findOrFail($order->unit_id))) {
            throw OnlineOrdersException::needsSupervisor();
        }
    }

    private function crmOn(Organization $company): bool
    {
        return class_exists(Customers::class) && app(Customers::class)->available($company);
    }

    private function warehouse(Organization $company, string $id): void
    {
        $warehouse = $this->stock->warehouse($company, $id);
        if ($warehouse === null || ! $warehouse['is_active']) {
            throw OnlineOrdersException::warehouseUnusable();
        }
    }

    /**
     * The order locked at the version seen, changed, its version moved on.
     *
     * @param  callable(Order): void  $change
     */
    private function change(Organization $company, Order $order, int $baseVersion, callable $change): Order
    {
        return $this->book->transaction($company, function () use ($company, $order, $baseVersion, $change) {
            $row = $this->book->query(Order::class, $company)->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($row->version !== $baseVersion) {
                throw OnlineOrdersException::versionConflict(['version' => $row->version, 'status' => $row->status]);
            }
            $change($row);
            $row->forceFill(['version' => $row->version + 1])->save();

            return $row;
        });
    }

    /** @param  array<string, mixed>  $extra */
    private function move(Organization $company, Order $row, string $to, User $actor, array $extra = [], ?string $note = null): void
    {
        $from = $row->status;
        $row->forceFill(['status' => $to, ...$extra]);
        $this->history($company, $row, $from, $to, $note, $actor);
        $this->audit->record("online_orders.order_{$to}", $row, old: ['status' => $from], new: ['status' => $to, 'number' => $row->number],
            actor: $actor, organizationId: $company->getKey());
    }

    private function history(Organization $company, Order $order, ?string $from, string $to, ?string $note, ?User $actor): void
    {
        (new OrderEvent)->fill(['organization_id' => $company->getKey(), 'order_id' => $order->getKey(), 'from_status' => $from, 'to_status' => $to,
            'note' => $note, 'actor_id' => $actor?->getKey(), 'created_at' => CarbonImmutable::now()])->save();
    }

    /** Goods back into the warehouse at exactly what they left at (once per order). */
    private function stockBack(Organization $company, Order $row, User $actor, string $type): void
    {
        $lines = $this->book->query(OrderLine::class, $company)->where('order_id', $row->getKey())->orderBy('line_no')->get();
        $this->stock->take($company, $row->warehouse_id, $lines->map(fn (OrderLine $line) => ['item_id' => $line->item_id, 'quantity_milli' => -$line->quantity_milli, 'value_minor' => $line->cost_minor])->all(),
            ['module' => 'online_orders', 'type' => $type, 'id' => $row->getKey(), 'actor_id' => $actor->getKey()], CarbonImmutable::now());
    }

    /** A payment (in) or refund (out), recorded and posted against what the customer owes. */
    private function money(Organization $company, Order $row, string $kind, string $method, int $amount, ?string $reference, string $opId, ?User $actor): void
    {
        $payment = new OrderPayment;
        $payment->fill(['organization_id' => $company->getKey(), 'order_id' => $row->getKey(), 'kind' => $kind, 'method' => $method, 'amount_minor' => $amount,
            'reference' => $reference, 'recorded_by' => $actor?->getKey(), 'op_id' => $opId, 'created_at' => CarbonImmutable::now()])->save();
        $in = $kind === 'payment';
        $journal = $this->postings->post($company, "online-order-{$kind}-{$payment->getKey()}", CarbonImmutable::now()->startOfDay(),
            __("online_orders::orders.narration.{$kind}", ['number' => $row->number]), $kind, $payment->getKey(), $row->currency_code, [
                $in ? LedgerLine::debit(OrderPostings::METHOD_KEYS[$method], $amount, $row->unit_id) : LedgerLine::credit(OrderPostings::METHOD_KEYS[$method], $amount, $row->unit_id),
                $in ? LedgerLine::credit('online_orders.receivable', $amount, $row->unit_id) : LedgerLine::debit('online_orders.receivable', $amount, $row->unit_id),
            ]);
        $payment->forceFill(['journal_id' => $journal])->save();
        $this->audit->record($in ? 'online_orders.payment_recorded' : 'online_orders.refund_made', $row,
            new: ['number' => $row->number, 'method' => $method, 'amount_minor' => $amount], actor: $actor, organizationId: $company->getKey());
    }

    /**
     * Lines at Inventory's price, quantities with no more decimals than the
     * unit allows, the discount within the limit, the delivery charge.
     *
     * @param  array<string, mixed>  $data
     * @return array{items: array<string, array<string, mixed>>, lines: list<array<string, mixed>>, totals: array<string, mixed>, zone: string|null}
     */
    private function price(Organization $company, Organization $branch, array $data, ?User $actor): array
    {
        $context = $this->contexts->forOrganization($branch);
        if ($data['payment_method'] === 'cod' && ! (bool) $this->rules->get('online_orders.cod_allowed', $context)) {
            throw OnlineOrdersException::codNotAllowed();
        }
        $includeTax = (bool) $this->rules->get('online_orders.prices_include_tax', $context);
        $items = collect($this->stock->items($company, array_values(array_unique(array_column($data['lines'], 'item_id')))))->keyBy('id');
        $units = $this->stock->units($company);
        $rates = $this->taxRates($company, $items->pluck('tax_code_id')->filter()->unique()->values()->all());
        $lines = [];
        foreach ($data['lines'] as $index => $line) {
            $item = $items[$line['item_id']] ?? throw OnlineOrdersException::itemNotSold($line['item_id']);
            if ($item['sale_price_minor'] === null) {
                throw OnlineOrdersException::itemNotSold($item['sku']);
            }
            $step = 10 ** (3 - min(3, (int) ($units[$item['unit_id']]['decimals'] ?? 0)));
            if ($line['quantity_milli'] <= 0 || $line['quantity_milli'] % $step !== 0) {
                throw ValidationException::withMessages(["lines.{$index}.quantity_milli" => __('online_orders::orders.validation.quantity')]);
            }
            $lines[] = ['item_id' => $item['id'], 'quantity_milli' => (int) $line['quantity_milli'], 'unit_price_minor' => (int) $item['sale_price_minor'],
                'discount_minor' => (int) ($line['discount_minor'] ?? 0), 'tax_rate_bp' => $rates[$item['tax_code_id'] ?? ''] ?? 0];
        }

        $zone = $data['delivery_zone'] ?? null;
        if ($zone !== null) {
            $found = collect($this->zones($branch))->firstWhere('code', $zone) ?? throw OnlineOrdersException::unknownZone($zone);
            $delivery = (int) $found['charge_minor'];
        } else {
            $delivery = (int) ($data['delivery_minor'] ?? 0);
        }
        $totals = OrderMath::order($lines, $includeTax, $delivery);

        $percent = (string) $this->rules->get('online_orders.max_discount_percent', $context);
        if (OrderMath::discountShare($totals['discount'], $totals['subtotal']) > OrderMath::percentToBp($percent) && ($actor === null || ! Gate::forUser($actor)->allows('online_orders.supervise', $branch))) {
            throw OnlineOrdersException::discountLimit($percent);
        }

        return ['items' => $items->all(), 'lines' => $lines, 'totals' => [...$totals, 'include_tax' => $includeTax], 'zone' => $zone];
    }

    /** @param  array<string, mixed>  $priced @return array<string, mixed> */
    private function totals(array $priced): array
    {
        $totals = $priced['totals'];

        return ['subtotal_minor' => $totals['subtotal'], 'discount_minor' => $totals['discount'], 'tax_minor' => $totals['tax'], 'delivery_minor' => $totals['delivery'],
            'total_minor' => $totals['total'], 'prices_include_tax' => $totals['include_tax'], 'delivery_zone' => $priced['zone']];
    }

    /** @param  array<string, mixed>  $priced */
    private function writeLines(Organization $company, Order $order, array $priced): void
    {
        foreach ($priced['lines'] as $index => $entry) {
            $item = $priced['items'][$entry['item_id']];
            $totals = $priced['totals']['lines'][$index];
            $line = new OrderLine;
            $line->fill([
                'organization_id' => $company->getKey(), 'order_id' => $order->getKey(), 'line_no' => $index + 1, 'item_id' => $item['id'], 'sku' => $item['sku'],
                'keeps_stock' => $item['kind'] === 'stock', 'quantity_milli' => $entry['quantity_milli'], 'unit_price_minor' => $entry['unit_price_minor'],
                'discount_minor' => $totals['discount'], 'tax_rate_bp' => $entry['tax_rate_bp'], 'tax_minor' => $totals['tax'], 'net_minor' => $totals['net'], 'total_minor' => $totals['total'],
            ]);
            $line->putTexts('name', $item['names']);
            $line->save();
        }
    }

    /**
     * The customer: name, address, the mobile number (kept in E.164, by the
     * company's country); with CRM on here, its contact (found by number,
     * else added at the order's branch).
     *
     * @param  array<string, mixed>  $data
     * @return array{customer_name: string, customer_phone: string, customer_id: string|null, address: string}
     */
    private function customer(Organization $company, Organization $branch, array $data, ?User $actor): array
    {
        $phone = PhoneNumber::normalize(trim((string) $data['customer_phone']), $this->book->country($company))
            ?? throw ValidationException::withMessages(['customer_phone' => __('online_orders::orders.validation.phone')]);
        $name = trim((string) $data['customer_name']);
        $contact = $this->crmOn($company) ? app(Customers::class)->remember($company, $branch->getKey(), $name, $phone, $actor) : null;

        return ['customer_name' => $name, 'customer_phone' => $phone, 'customer_id' => $contact['id'] ?? null, 'address' => trim((string) $data['address'])];
    }

    /** @return array<string, int> */
    private function taxRates(Organization $company, array $ids): array
    {
        return $ids === [] || ! $this->modules->isEnabled('accounting', $company) ? [] : app(TaxCodes::class)->salesRates($company, $ids);
    }

    /** "OO-2026-000001": the prefix (rule online_orders.number_prefix), the year, a running number. */
    private function number(Organization $company, CarbonImmutable $on): string
    {
        $year = (int) $on->format('Y');
        $sequence = $this->book->query(Sequence::class, $company)->where('year', $year)->lockForUpdate()->first();
        if ($sequence === null) {
            $sequence = new Sequence;
            $sequence->fill(['organization_id' => $company->getKey(), 'year' => $year, 'next' => 1]);
        }
        $number = $sequence->next;
        $sequence->next = $number + 1;
        $sequence->save();

        return sprintf('%s-%d-%06d', (string) $this->rules->get('online_orders.number_prefix', $this->contexts->forOrganization($company)), $year, $number);
    }
}
