<?php

use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\RuleTargets;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Modules\Accounting\Models\JournalLine;
use Modules\OnlineOrders\Services\Orders;

/*
 * SC-7: the courier cancels a parcel already out for delivery, so it is
 * coming back (rule online_orders.courier_cancel_means_return). It shows
 * under Returning parcels and in the bell (late after
 * online_orders.return_overdue_days) until staff take it back: goods back
 * into stock, order cancelled, money paid ahead given back less the delivery
 * charge (online_orders.keep_delivery_on_return). Permission, module,
 * company and partner boundaries.
 */

const RETURN_STEADFAST_API = 'https://portal.packzy.com/api/v1';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        setPlan($group, 'business');
        foreach (['inventory', 'online_orders', 'accounting'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    $role = fn ($company, array $permissions, string $name) => orgToken(staffWithRoles($company, makeRole($company, $permissions, $name)), $company);
    $keeper = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['inventory.view', 'inventory.manage', 'accounting.view', 'accounting.manage'], 'Back office')), $this->w->c1);
    $this->staff = $role($this->w->c1, ['online_orders.view', 'online_orders.manage', 'online_orders.couriers'], 'Order desk');
    $this->receiver = $role($this->w->c1, ['online_orders.view', 'online_orders.receive_returns'], 'Store keeper');
    setUpBooks($this, $keeper, $this->w->c1)->assertCreated();
    $inv = fn (string $path) => "/api/organizations/{$this->w->c1->id}/inventory/{$path}";
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->b1)->id."/online-orders/{$path}";

    $pcs = $this->asToken($keeper)->postJson($inv('units'), ['code' => 'PCS', 'name' => ['en' => 'Pieces'], 'decimals' => 0])->assertCreated()->json('data');
    $this->store = $this->asToken($keeper)->postJson($inv('warehouses'), ['code' => 'WEB', 'name' => ['en' => 'Web store'], 'unit_id' => $this->w->c1->id])->assertCreated()->json('data');
    $this->pen = $this->asToken($keeper)->postJson($inv('items'), ['unit_id' => $pcs['id'], 'kind' => 'stock', 'sku' => 'PEN', 'name' => ['en' => 'Pen'], 'sale_price_minor' => 23000])->assertCreated()->json('data');
    $receipt = $this->asToken($keeper)->postJson($inv('documents'), ['type' => 'receipt', 'warehouse_id' => $this->store['id'], 'document_date' => '2026-11-01', 'lines' => [
        ['item_id' => $this->pen['id'], 'quantity_milli' => 100000, 'unit_cost_minor' => 10000],
    ]])->assertCreated()->json('data');
    $this->asToken($keeper)->postJson($inv("documents/{$receipt['id']}/post"), ['base_version' => $receipt['version']])->assertOk();
    ruleService()->set(app(RuleTargets::class)->organization($this->w->c1->fresh()), 'online_orders.delivery_zones', RuleMode::Set, [
        ['code' => 'dhaka', 'name' => ['en' => 'Inside Dhaka'], 'charge_minor' => 6000],
    ], 'Test setup', trusted: true);

    $this->courier = $this->asToken($this->staff)->postJson('/api/organizations/'.$this->w->c1->id.'/online-orders/couriers', [
        'driver' => 'steadfast', 'name' => 'Steadfast', 'credentials' => ['api_key' => 'k', 'secret_key' => 's'],
    ])->assertCreated()->json('data');

    // One fake (fakes pile up, the first wins): the consignment number set for the booking.
    Http::fake([RETURN_STEADFAST_API.'/create_order' => fn () => Http::response(['status' => 200, 'consignment' => ['consignment_id' => $this->nextCid, 'tracking_code' => "T{$this->nextCid}", 'status' => 'in_review']])]);
    // A cash-on-delivery order of 290 taka (230 + 60), 2 pens, booked as consignment $cid.
    $this->booked = function (int $cid, int $paidAhead = 0) {
        $order = $this->asToken($this->staff)->postJson(($this->api)('orders'), [
            'op_id' => (string) str()->ulid(), 'warehouse_id' => $this->store['id'], 'customer_name' => 'Karim Uddin', 'customer_phone' => '01712345678',
            'address' => 'House 4, Road 2, Dhanmondi', 'payment_method' => 'cod', 'delivery_zone' => 'dhaka', 'lines' => [['item_id' => $this->pen['id'], 'quantity_milli' => 1000]],
        ])->assertCreated()->json('data');
        if ($paidAhead > 0) {
            $order = $this->asToken($this->staff)->postJson(($this->api)("orders/{$order['id']}/payments"), ['op_id' => (string) str()->ulid(), 'method' => 'mobile', 'amount_minor' => $paidAhead])->assertCreated()->json('data');
        }
        $order = $this->asToken($this->staff)->postJson(($this->api)("orders/{$order['id']}/confirm"), ['base_version' => $order['version']])->assertOk()->json('data');
        $this->nextCid = $cid;

        return $this->asToken($this->staff)->postJson(($this->api)("orders/{$order['id']}/book"), ['base_version' => $order['version'], 'courier_id' => $this->courier['id']])->assertOk()->json('data');
    };
    $this->says = fn (int $cid, string $status) => $this->withToken($this->courier['webhook_token'])
        ->postJson("/api/couriers/{$this->w->c1->id}/{$this->courier['id']}/webhook", ['notification_type' => 'delivery_status', 'consignment_id' => $cid, 'status' => $status])->assertOk();
    $this->returns = fn ($token = null, $unit = null) => $this->asToken($token ?? $this->receiver)->getJson(($this->api)('returns', $unit ?? $this->w->c1));
    // The version is read first (as the order desk), then sent as the one receiving.
    $this->receive = function (array $order, array $body = [], $token = null) {
        $version = $this->asToken($this->staff)->getJson(($this->api)("orders/{$order['id']}"))->json('data.version');

        return $this->asToken($token ?? $this->receiver)->postJson(($this->api)("orders/{$order['id']}/receive-back"), ['base_version' => $version, ...$body]);
    };
    $this->onHand = fn () => app(Orders::class)->available($this->w->c1, $this->store['id'], [$this->pen['id']])[$this->pen['id']] ?? 0;
    $this->balance = fn (string $code) => (int) JournalLine::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)
        ->where('organization_id', $this->w->c1->id)->where('account_id', accountId($this->w->c1, $code))->get()->sum(fn ($line) => $line->debit_minor - $line->credit_minor);
});

it('marks a parcel the courier cancelled as coming back and takes it back into stock', function () {
    $order = ($this->booked)(7001);
    expect(($this->onHand)())->toBe(99000);
    ($this->says)(7001, 'cancelled');

    $list = ($this->returns)()->assertOk()->json('data');
    expect($list)->toHaveCount(1)
        ->and($list[0])->toMatchArray(['id' => $order['id'], 'status' => 'shipped', 'overdue' => false, 'days_on_way' => 0])
        ->and($list[0]['items'][0])->toMatchArray(['name' => 'Pen', 'quantity_milli' => 1000]);
    $this->asToken($this->receiver)->getJson('/api/attention')->assertOk()->assertJsonFragment(['key' => 'online_orders.returning', 'count' => 1, 'tone' => 'info']);

    // Late after 7 days (rule return_overdue_days): the bell warns.
    $this->travel(8)->days();
    $this->receiver = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['online_orders.view', 'online_orders.receive_returns'], 'Store keeper 2')), $this->w->c1);
    $this->staff = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['online_orders.view', 'online_orders.manage'], 'Order desk 2')), $this->w->c1);
    expect(($this->returns)()->json('data.0'))->toMatchArray(['overdue' => true, 'days_on_way' => 8]);
    $this->asToken($this->receiver)->getJson('/api/attention')->assertJsonFragment(['key' => 'online_orders.returning', 'tone' => 'warn']);

    $done = ($this->receive)($order, ['note' => 'Box opened'])->assertOk()->json('data');
    expect($done['status'])->toBe('cancelled')
        ->and($done['received_back_at'])->not->toBeNull()
        ->and($done['cancel_reason'])->toContain('Box opened')
        ->and(($this->onHand)())->toBe(100000)
        ->and(($this->returns)()->json('data'))->toBe([]);
    // Taken back once: again is refused.
    ($this->receive)($order)->assertStatus(409)->assertJsonPath('code', 'not_coming_back');
});

it('clears the mark when the courier delivers after all, and does nothing when the rule is off', function () {
    $order = ($this->booked)(7101);
    ($this->says)(7101, 'cancelled');
    ($this->says)(7101, 'delivered');
    expect(($this->returns)()->json('data'))->toBe([])
        ->and($this->asToken($this->staff)->getJson(($this->api)("orders/{$order['id']}"))->json('data'))->toMatchArray(['status' => 'delivered', 'courier_returning_at' => null]);

    ruleService()->set(app(RuleTargets::class)->organization($this->w->c1->fresh()), 'online_orders.courier_cancel_means_return', RuleMode::Set, false, 'Test', trusted: true);
    $other = ($this->booked)(7102);
    ($this->says)(7102, 'cancelled');
    expect(($this->returns)()->json('data'))->toBe([]);
    ($this->receive)($other)->assertStatus(409);
});

it('keeps the delivery charge paid ahead and gives the rest back', function () {
    // Paid the delivery charge (60) ahead by mobile: kept, nothing to give back, delivery income in the books.
    $first = ($this->booked)(7201, 6000);
    ($this->says)(7201, 'cancelled');
    $done = ($this->receive)($first)->assertOk()->json('data');
    expect($done)->toMatchArray(['status' => 'cancelled', 'paid_minor' => 6000, 'refunded_minor' => 0])
        ->and(($this->balance)('4200'))->toBe(-6000)
        ->and(($this->balance)('1130'))->toBe(6000);

    // Paid all of it (290): the 230 beyond the delivery charge goes back, so where to is asked.
    $second = ($this->booked)(7202, 29000);
    ($this->says)(7202, 'cancelled');
    ($this->receive)($second)->assertStatus(422)->assertJsonPath('code', 'refund_method_missing');
    expect(($this->receive)($second, ['refund_method' => 'mobile'])->assertOk()->json('data'))->toMatchArray(['refunded_minor' => 23000])
        ->and(($this->balance)('4200'))->toBe(-12000)
        ->and(($this->balance)('1130'))->toBe(6000 + 6000);

    // Rule off: everything paid goes back.
    ruleService()->set(app(RuleTargets::class)->organization($this->w->c1->fresh()), 'online_orders.keep_delivery_on_return', RuleMode::Set, false, 'Test', trusted: true);
    $third = ($this->booked)(7203, 6000);
    ($this->says)(7203, 'cancelled');
    expect(($this->receive)($third, ['refund_method' => 'mobile'])->assertOk()->json('data'))->toMatchArray(['refunded_minor' => 6000]);
});

it('checks what is sent: the version seen, no unknown fields, a known refund method', function () {
    $order = ($this->booked)(7301);
    ($this->says)(7301, 'cancelled');
    $this->asToken($this->receiver)->postJson(($this->api)("orders/{$order['id']}/receive-back"), [])->assertUnprocessable()->assertJsonValidationErrors('base_version');
    ($this->receive)($order, ['organization_id' => $this->w->c2->id])->assertUnprocessable();
    ($this->receive)($order, ['refund_method' => 'gold'])->assertUnprocessable()->assertJsonValidationErrors('refund_method');
    ($this->receive)($order, ['note' => str_repeat('x', 201)])->assertUnprocessable()->assertJsonValidationErrors('note');
    ($this->receive)($order, ['base_version' => 1])->assertStatus(409);
    // An order the courier is not bringing back.
    $out = ($this->booked)(7302);
    ($this->receive)($out)->assertStatus(409)->assertJsonPath('code', 'not_coming_back');
});

it('needs the receive permission and Online orders on', function () {
    $order = ($this->booked)(7401);
    ($this->says)(7401, 'cancelled');
    ($this->returns)($this->staff)->assertForbidden();
    ($this->receive)($order, [], $this->staff)->assertForbidden();
    $this->asToken($this->staff)->getJson('/api/attention')->assertOk()->assertJsonMissing(['key' => 'online_orders.returning']);

    toggles()->disable($this->w->g1, 'online_orders', 'Test', confirm: true);
    ($this->returns)()->assertForbidden();
    $this->asToken($this->receiver)->postJson(($this->api)("orders/{$order['id']}/receive-back"), ['base_version' => 1])->assertForbidden();
});

it('keeps each company to its own returning parcels', function () {
    $order = ($this->booked)(7501);
    ($this->says)(7501, 'cancelled');
    $c2 = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['online_orders.view', 'online_orders.receive_returns'], 'C2 keeper')), $this->w->c2);
    $c4 = orgToken(staffWithRoles($this->w->c4, makeRole($this->w->c4, ['online_orders.view', 'online_orders.receive_returns'], 'C4 keeper')), $this->w->c4);
    foreach ([$c2, $c4] as $token) {
        ($this->returns)($token)->assertNotFound();
        $this->asToken($token)->postJson(($this->api)("orders/{$order['id']}/receive-back"), ['base_version' => 5])->assertNotFound();
    }
    expect(($this->returns)($c2, $this->w->c2)->assertOk()->json('data'))->toBe([]);
    $this->asToken($c2)->postJson(($this->api)("orders/{$order['id']}/receive-back", $this->w->c2), ['base_version' => 5])->assertNotFound();
    expect(($this->returns)()->json('data'))->toHaveCount(1);
});
