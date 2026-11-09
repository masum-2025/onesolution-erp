<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\RuleTargets;
use Carbon\CarbonImmutable;
use Modules\OnlineOrders\Services\OrderMath;

/*
 * OO-1: online orders taken at a branch: priced from Inventory's items
 * (VAT inside the price), delivery charge by zone (rule), cash on delivery
 * where allowed; confirmed orders hold their stock, shipped ones take it,
 * delivered ones are posted and paid; cancelled and taken-back orders give
 * stock and money back; supervisors only where it matters; isolation
 * between companies and partners; off when the module is off.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        // Beyond the starter plan online orders is an add-on; the business plan has every module.
        setPlan($group, 'business');
        foreach (['inventory', 'online_orders', 'accounting', 'crm'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    $role = fn (array $permissions, string $name) => staffWithRoles($this->w->c1, makeRole($this->w->c1, $permissions, $name));
    $this->keeperUser = $role(['inventory.view', 'inventory.manage', 'accounting.view', 'accounting.manage', 'accounting.tax'], 'Back office');
    $this->keeper = orgToken($this->keeperUser, $this->w->c1);
    $this->staff = orgToken($role(['online_orders.view', 'online_orders.manage'], 'Order desk'), $this->w->c1);
    $this->supervisorUser = $role(['online_orders.view', 'online_orders.manage', 'online_orders.supervise'], 'Order supervisor');
    $this->supervisor = orgToken($this->supervisorUser, $this->w->c1);
    setUpBooks($this, $this->keeper, $this->w->c1)->assertCreated();
    $inv = fn (string $path) => "/api/organizations/{$this->w->c1->id}/inventory/{$path}";
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->b1)->id."/online-orders/{$path}";

    $vat = $this->asToken($this->keeper)->postJson("/api/organizations/{$this->w->c1->id}/accounting/tax-codes", ['code' => 'V15', 'name' => ['en' => 'VAT 15%'], 'rate_bp' => 1500, 'kind' => 'standard', 'applies_to' => 'sales'])->assertCreated()->json('data');
    $pcs = $this->asToken($this->keeper)->postJson($inv('units'), ['code' => 'PCS', 'name' => ['en' => 'Pieces'], 'decimals' => 0])->assertCreated()->json('data');
    $this->store = $this->asToken($this->keeper)->postJson($inv('warehouses'), ['code' => 'WEB', 'name' => ['en' => 'Web store'], 'unit_id' => $this->w->c1->id])->assertCreated()->json('data');
    $item = fn (array $data) => $this->asToken($this->keeper)->postJson($inv('items'), ['unit_id' => $pcs['id'], 'kind' => 'stock', ...$data])->assertCreated()->json('data');
    $this->soap = $item(['sku' => 'SOAP', 'name' => ['en' => 'Soap', 'bn' => 'সাবান'], 'sale_price_minor' => 11500, 'tax_code_id' => $vat['id']]);
    $this->pen = $item(['sku' => 'PEN', 'name' => ['en' => 'Pen'], 'sale_price_minor' => 1000]);
    $receipt = $this->asToken($this->keeper)->postJson($inv('documents'), ['type' => 'receipt', 'warehouse_id' => $this->store['id'], 'document_date' => '2026-11-01', 'lines' => [
        ['item_id' => $this->soap['id'], 'quantity_milli' => 5000, 'unit_cost_minor' => 6000], ['item_id' => $this->pen['id'], 'quantity_milli' => 100000, 'unit_cost_minor' => 500],
    ]])->assertCreated()->json('data');
    $this->asToken($this->keeper)->postJson($inv("documents/{$receipt['id']}/post"), ['base_version' => $receipt['version']])->assertOk();

    ruleService()->set(app(RuleTargets::class)->organization($this->w->c1->fresh()), 'online_orders.delivery_zones', RuleMode::Set, [
        ['code' => 'dhaka', 'name' => ['en' => 'Inside Dhaka', 'bn' => 'ঢাকার ভেতরে'], 'charge_minor' => 6000],
        ['code' => 'outside', 'name' => ['en' => 'Outside Dhaka'], 'charge_minor' => 12000],
    ], 'Test setup', trusted: true);

    $this->place = fn (array $lines, array $extra = [], ?string $token = null) => $this->asToken($token ?? $this->staff)->postJson(($this->api)('orders'), [
        'op_id' => (string) str()->ulid(), 'warehouse_id' => $this->store['id'], 'customer_name' => 'Karim Uddin', 'customer_phone' => '01712345678',
        'address' => 'House 4, Road 2, Dhanmondi', 'payment_method' => 'cod', 'delivery_zone' => 'dhaka', 'lines' => $lines, ...$extra,
    ]);
    $this->step = fn (array $order, string $step, array $body = [], ?string $token = null) => $this->asToken($token ?? $this->staff)
        ->postJson(($this->api)("orders/{$order['id']}/{$step}"), ['base_version' => $order['version'], ...$body]);
    $this->onHand = fn (array $item) => collect($this->asToken($this->keeper)->getJson($inv('stock'))->json('data'))->firstWhere('item_id', $item['id'])['quantity_milli'];
    $this->row = fn (string $code) => collect($this->asToken($this->keeper)->getJson("/api/organizations/{$this->w->c1->id}/accounting/reports/trial-balance?as_of=2026-11-30")->json('data.rows'))->firstWhere('code', $code);
});

it('takes an order at a branch: VAT inside the price, the zone\'s delivery charge, a number, once per op id', function () {
    $op = (string) str()->ulid();
    $order = ($this->place)([['item_id' => $this->soap['id'], 'quantity_milli' => 2000], ['item_id' => $this->pen['id'], 'quantity_milli' => 3000]], ['op_id' => $op])
        ->assertCreated()->json('data');
    expect($order)->toMatchArray(['status' => 'draft', 'channel' => 'manual', 'number' => 'OO-2026-000001', 'unit_id' => $this->w->b1->id, 'customer_phone' => '+8801712345678',
        'subtotal_minor' => 26000, 'tax_minor' => 3000, 'delivery_minor' => 6000, 'total_minor' => 32000, 'due_minor' => 32000])
        ->and($order['lines'][0])->toMatchArray(['sku' => 'SOAP', 'tax_minor' => 3000, 'total_minor' => 23000])
        ->and($order['history'][0]['to'])->toBe('draft')
        ->and($order['customer_id'])->not->toBeNull();
    expect(($this->place)([['item_id' => $this->pen['id'], 'quantity_milli' => 1000]], ['op_id' => $op])->json('data.id'))->toBe($order['id']);

    // Checks: the phone, the zone, unknown fields, quantities the unit does not allow, no selling price.
    ($this->place)([['item_id' => $this->pen['id'], 'quantity_milli' => 1000]], ['customer_phone' => '12'])->assertUnprocessable()->assertJsonValidationErrors('customer_phone');
    ($this->place)([['item_id' => $this->pen['id'], 'quantity_milli' => 1000]], ['delivery_zone' => 'mars'])->assertUnprocessable()->assertJsonPath('code', 'unknown_zone');
    ($this->place)([['item_id' => $this->pen['id'], 'quantity_milli' => 1000]], ['organization_id' => $this->w->c2->id])->assertUnprocessable();
    ($this->place)([['item_id' => $this->pen['id'], 'quantity_milli' => 1500]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity_milli');
    ($this->place)([['item_id' => '01HZNOTANITEM0000000000000', 'quantity_milli' => 1000]])->assertUnprocessable()->assertJsonPath('code', 'item_not_sold');

    // Without zones the charge is typed; cash on delivery switched off for the company.
    $typed = ($this->place)([['item_id' => $this->pen['id'], 'quantity_milli' => 1000]], ['delivery_zone' => null, 'delivery_minor' => 5000])->assertCreated()->json('data');
    expect($typed)->toMatchArray(['delivery_minor' => 5000, 'total_minor' => 6000]);
    ruleService()->set(app(RuleTargets::class)->organization($this->w->c1->fresh()), 'online_orders.cod_allowed', RuleMode::Set, false, 'Test setup', trusted: true);
    ($this->place)([['item_id' => $this->pen['id'], 'quantity_milli' => 1000]])->assertUnprocessable()->assertJsonPath('code', 'cod_not_allowed');
    ($this->place)([['item_id' => $this->pen['id'], 'quantity_milli' => 1000]], ['payment_method' => 'prepaid'])->assertCreated();

    expect(AuditLog::query()->where('action', 'online_orders.order_placed')->count())->toBe(3);
    expect(collect($this->asToken($this->staff)->getJson(($this->api)('orders').'?search=01712345678')->assertOk()->json('data'))->pluck('number'))->toContain('OO-2026-000001');
});

it('changes a draft and keeps big discounts for supervisors', function () {
    $order = ($this->place)([['item_id' => $this->pen['id'], 'quantity_milli' => 10000]])->json('data');
    $changed = $this->asToken($this->staff)->patchJson(($this->api)("orders/{$order['id']}"), ['base_version' => $order['version'], 'delivery_zone' => 'outside',
        'lines' => [['item_id' => $this->pen['id'], 'quantity_milli' => 10000, 'discount_minor' => 1000]]])->assertOk()->json('data');
    expect($changed)->toMatchArray(['delivery_minor' => 12000, 'discount_minor' => 1000, 'total_minor' => 9000 + 12000, 'version' => 2]);
    $this->asToken($this->staff)->patchJson(($this->api)("orders/{$order['id']}"), ['base_version' => 1, 'note' => 'Late'])->assertConflict()->assertJsonPath('code', 'version_conflict');

    // 20% off is above the 10% staff may give.
    $big = [['item_id' => $this->pen['id'], 'quantity_milli' => 10000, 'discount_minor' => 2000]];
    $this->asToken($this->staff)->patchJson(($this->api)("orders/{$order['id']}"), ['base_version' => 2, 'lines' => $big])->assertForbidden()->assertJsonPath('code', 'discount_limit');
    $this->asToken($this->supervisor)->patchJson(($this->api)("orders/{$order['id']}"), ['base_version' => 2, 'lines' => $big])->assertOk()->assertJsonPath('data.discount_minor', 2000);
});

it('holds stock once confirmed, so other orders see less; sends it out at cost; posts and takes the money on delivery', function () {
    $first = ($this->place)([['item_id' => $this->soap['id'], 'quantity_milli' => 4000]])->json('data');
    $second = ($this->place)([['item_id' => $this->soap['id'], 'quantity_milli' => 2000]])->json('data');
    $first = ($this->step)($first, 'confirm')->assertOk()->assertJsonPath('data.status', 'confirmed')->json('data');
    expect(collect($this->asToken($this->staff)->getJson(($this->api)('items')."?warehouse_id={$this->store['id']}")->assertOk()->json('data'))->firstWhere('sku', 'SOAP')['available_milli'])->toBe(1000);
    ($this->step)($second, 'confirm')->assertConflict()->assertJsonPath('code', 'not_enough_stock');
    ($this->step)($first, 'confirm')->assertConflict()->assertJsonPath('code', 'wrong_status');
    ($this->step)($first, 'deliver')->assertConflict()->assertJsonPath('code', 'wrong_status');

    $first = ($this->step)($first, 'pack')->assertOk()->json('data');
    $first = ($this->step)($first, 'ship', ['courier' => 'Pathao', 'tracking' => 'PA-1001'])->assertOk()->json('data');
    expect($first)->toMatchArray(['status' => 'shipped', 'courier' => 'Pathao'])
        ->and(($this->onHand)($this->soap))->toBe(1000);
    // Four soaps 460.00 (60.00 VAT inside) and delivery 60.00, cash on delivery.
    $first = ($this->step)($first, 'deliver')->assertOk()->json('data');
    expect($first)->toMatchArray(['status' => 'delivered', 'total_minor' => 52000, 'due_minor' => 52000])
        ->and(($this->row)('1140')['debit_minor'])->toBe(52000)
        ->and(($this->row)('4100')['credit_minor'])->toBe(40000)
        ->and(($this->row)('2130')['credit_minor'])->toBe(6000)
        ->and(($this->row)('4200')['credit_minor'])->toBe(6000)
        ->and(($this->row)('5100')['debit_minor'])->toBe(24000);

    $pay = fn (int $amount, ?string $op = null) => $this->asToken($this->staff)->postJson(($this->api)("orders/{$first['id']}/payments"), ['op_id' => $op ?? (string) str()->ulid(), 'method' => 'cash', 'amount_minor' => $amount]);
    $pay(60000)->assertUnprocessable()->assertJsonPath('code', 'overpaid');
    $op = (string) str()->ulid();
    $paid = $pay(52000, $op)->assertCreated()->json('data');
    expect($paid)->toMatchArray(['paid_minor' => 52000, 'due_minor' => 0])
        ->and($pay(52000, $op)->json('data.paid_minor'))->toBe(52000)
        ->and(($this->row)('1110')['debit_minor'])->toBe(52000);
    $pay(100)->assertConflict()->assertJsonPath('code', 'nothing_due');
    expect(AuditLog::query()->where('action', 'online_orders.order_delivered')->count())->toBe(1);
});

it('sends a prepaid order only once paid', function () {
    $order = ($this->place)([['item_id' => $this->pen['id'], 'quantity_milli' => 2000]], ['payment_method' => 'prepaid'])->json('data');
    $order = ($this->step)($order, 'confirm')->json('data');
    ($this->step)($order, 'ship')->assertConflict()->assertJsonPath('code', 'prepaid_not_paid');
    $order = $this->asToken($this->staff)->postJson(($this->api)("orders/{$order['id']}/payments"), ['op_id' => 'pay-1', 'method' => 'mobile', 'amount_minor' => 8000, 'reference' => 'TRX123'])->assertCreated()->json('data');
    ($this->step)($order, 'ship')->assertOk()->assertJsonPath('data.status', 'shipped');
});

it('cancels: before shipping the hold goes; once shipped only a supervisor, stock and money come back', function () {
    $order = ($this->place)([['item_id' => $this->soap['id'], 'quantity_milli' => 5000]])->json('data');
    $order = ($this->step)($order, 'confirm')->json('data');
    ($this->step)($order, 'cancel', ['reason' => 'Customer changed mind'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    $again = ($this->place)([['item_id' => $this->soap['id'], 'quantity_milli' => 5000]])->json('data');
    $again = ($this->step)($again, 'confirm')->assertOk()->json('data');

    $again = $this->asToken($this->staff)->postJson(($this->api)("orders/{$again['id']}/payments"), ['op_id' => 'adv-1', 'method' => 'mobile', 'amount_minor' => 10000])->json('data');
    $again = ($this->step)($again, 'ship')->json('data');
    expect(($this->onHand)($this->soap))->toBe(0);
    ($this->step)($again, 'cancel', ['reason' => 'Refused at the door'])->assertForbidden()->assertJsonPath('code', 'needs_supervisor');
    ($this->step)($again, 'cancel', ['reason' => 'Refused at the door'], $this->supervisor)->assertUnprocessable()->assertJsonPath('code', 'refund_method_missing');
    $done = ($this->step)($again, 'cancel', ['reason' => 'Refused at the door', 'refund_method' => 'mobile'], $this->supervisor)->assertOk()->json('data');
    expect($done)->toMatchArray(['status' => 'cancelled', 'refunded_minor' => 10000, 'due_minor' => 0])
        ->and(($this->onHand)($this->soap))->toBe(5000);
    // The advance in and back out of the mobile wallet: nothing left there.
    $wallet = ($this->row)('1130') ?? [];
    expect(($wallet['debit_minor'] ?? 0) - ($wallet['credit_minor'] ?? 0))->toBe(0);
});

it('takes a delivered order back within the days allowed: goods back, sale reversed, money back but the delivery', function () {
    $order = ($this->place)([['item_id' => $this->soap['id'], 'quantity_milli' => 1000]])->json('data');
    foreach (['confirm', 'ship', 'deliver'] as $step) {
        $order = ($this->step)($order, $step)->assertOk()->json('data');
    }
    $order = $this->asToken($this->staff)->postJson(($this->api)("orders/{$order['id']}/payments"), ['op_id' => 'cod-1', 'method' => 'cash', 'amount_minor' => 17500])->json('data');
    ($this->step)($order, 'return', ['reason' => 'Wrong colour', 'refund_method' => 'cash'])->assertForbidden();
    $back = ($this->step)($order, 'return', ['reason' => 'Wrong colour', 'refund_method' => 'cash'], $this->supervisor)->assertOk()->json('data');
    // 175.00 paid: 115.00 goods back, the 60.00 delivery kept.
    expect($back)->toMatchArray(['status' => 'returned', 'refunded_minor' => 11500, 'due_minor' => 0])
        ->and(($this->onHand)($this->soap))->toBe(5000)
        // The sale reversed to nothing (a zero account leaves the trial balance); the delivery income stays.
        ->and(($this->row)('4100'))->toBeNull()
        ->and(($this->row)('4200')['credit_minor'])->toBe(6000);

    $late = ($this->place)([['item_id' => $this->pen['id'], 'quantity_milli' => 1000]])->json('data');
    foreach (['confirm', 'ship', 'deliver'] as $step) {
        $late = ($this->step)($late, $step)->json('data');
    }
    $this->travel(8)->days();
    // A fresh sign-in after the days gone by.
    $this->supervisor = orgToken($this->supervisorUser, $this->w->c1);
    ($this->step)($late, 'return', ['reason' => 'Late'], $this->supervisor)->assertConflict()->assertJsonPath('code', 'return_too_late');
});

it('keeps orders to the company and the partner, to permission holders, and off when the module is off', function () {
    $order = ($this->place)([['item_id' => $this->pen['id'], 'quantity_milli' => 1000]])->json('data');

    // Another company of the same group, and a company of another partner.
    foreach ([$this->w->c2, $this->w->c4] as $other) {
        $token = orgToken(staffWithRoles($other, makeRole($other, ['online_orders.view', 'online_orders.manage', 'online_orders.supervise'], 'Desk')), $other);
        expect($this->asToken($token)->getJson(($this->api)('orders', $other))->assertOk()->json('data'))->toBe([]);
        $this->asToken($token)->getJson(($this->api)("orders/{$order['id']}", $other))->assertNotFound();
        $this->asToken($token)->postJson(($this->api)("orders/{$order['id']}/confirm", $other), ['base_version' => 1])->assertNotFound();
        $this->asToken($token)->getJson(($this->api)('orders', $this->w->b1))->assertNotFound();
    }

    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['online_orders.view'], 'Looker')), $this->w->c1);
    $this->asToken($viewer)->getJson(($this->api)("orders/{$order['id']}"))->assertOk();
    ($this->step)($order, 'confirm', [], $viewer)->assertForbidden();
    $nobody = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view'], 'Not orders')), $this->w->c1);
    $this->asToken($nobody)->getJson(($this->api)('orders'))->assertForbidden();

    toggles()->disable($this->w->g1, 'online_orders', 'Test setup', confirm: true);
    $this->asToken($this->staff)->getJson(($this->api)('orders'))->assertForbidden();
});

it('prices with integers: VAT inside or on top, delivery untaxed', function () {
    $inside = OrderMath::order([['quantity_milli' => 3000, 'unit_price_minor' => 11500, 'discount_minor' => 500, 'tax_rate_bp' => 1500]], true, 6000);
    expect($inside)->toMatchArray(['subtotal' => 34500, 'discount' => 500, 'tax' => 4435, 'goods' => 34000, 'delivery' => 6000, 'total' => 40000]);
    $onTop = OrderMath::order([['quantity_milli' => 1000, 'unit_price_minor' => 10000, 'discount_minor' => 0, 'tax_rate_bp' => 1500]], false, 0);
    expect($onTop)->toMatchArray(['tax' => 1500, 'total' => 11500])
        ->and(OrderMath::percentToBp('12.5'))->toBe(1250)
        ->and(OrderMath::discountShare(2000, 10000))->toBe(2000);
});
