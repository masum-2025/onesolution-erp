<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\RuleTargets;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\OnlineOrders\Models\Courier;

/*
 * SC-5b: a company's courier accounts (Steadfast first): keys kept encrypted
 * and never shown again; an order booked with the courier goes out with its
 * tracking code and the cash left to collect; the courier's notices (its own
 * Bearer token) and a refresh say where the parcel stands, and "delivered"
 * completes the order where the rule allows; rule, permission, company,
 * partner and module boundaries.
 */

const STEADFAST = 'https://portal.packzy.com/api/v1';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        setPlan($group, 'business');
        foreach (['inventory', 'online_orders', 'accounting', 'crm'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    $role = fn ($company, array $permissions, string $name) => orgToken(staffWithRoles($company, makeRole($company, $permissions, $name)), $company);
    $keeperUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['inventory.view', 'inventory.manage', 'accounting.view', 'accounting.manage'], 'Back office'));
    $keeper = orgToken($keeperUser, $this->w->c1);
    $this->staff = $role($this->w->c1, ['online_orders.view', 'online_orders.manage'], 'Order desk');
    $this->admin = $role($this->w->c1, ['online_orders.view', 'online_orders.manage', 'online_orders.couriers'], 'Shop admin');
    setUpBooks($this, $keeper, $this->w->c1)->assertCreated();
    $inv = fn (string $path) => "/api/organizations/{$this->w->c1->id}/inventory/{$path}";
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->b1)->id."/online-orders/{$path}";
    $this->couriersApi = fn ($company = null) => '/api/organizations/'.($company ?? $this->w->c1)->id.'/online-orders/couriers';

    $pcs = $this->asToken($keeper)->postJson($inv('units'), ['code' => 'PCS', 'name' => ['en' => 'Pieces'], 'decimals' => 0])->assertCreated()->json('data');
    $store = $this->asToken($keeper)->postJson($inv('warehouses'), ['code' => 'WEB', 'name' => ['en' => 'Web store'], 'unit_id' => $this->w->c1->id])->assertCreated()->json('data');
    $this->pen = $this->asToken($keeper)->postJson($inv('items'), ['unit_id' => $pcs['id'], 'kind' => 'stock', 'sku' => 'PEN', 'name' => ['en' => 'Pen'], 'sale_price_minor' => 23000])->assertCreated()->json('data');
    $receipt = $this->asToken($keeper)->postJson($inv('documents'), ['type' => 'receipt', 'warehouse_id' => $store['id'], 'document_date' => '2026-11-01', 'lines' => [
        ['item_id' => $this->pen['id'], 'quantity_milli' => 100000, 'unit_cost_minor' => 10000],
    ]])->assertCreated()->json('data');
    $this->asToken($keeper)->postJson($inv("documents/{$receipt['id']}/post"), ['base_version' => $receipt['version']])->assertOk();
    ruleService()->set(app(RuleTargets::class)->organization($this->w->c1->fresh()), 'online_orders.delivery_zones', RuleMode::Set, [
        ['code' => 'dhaka', 'name' => ['en' => 'Inside Dhaka'], 'charge_minor' => 6000],
    ], 'Test setup', trusted: true);

    // A confirmed cash-on-delivery order: 230 + 60 delivery = 290 taka.
    $this->confirmed = function () use ($store) {
        $order = $this->asToken($this->staff)->postJson(($this->api)('orders'), [
            'op_id' => (string) str()->ulid(), 'warehouse_id' => $store['id'], 'customer_name' => 'Karim Uddin', 'customer_phone' => '01712345678',
            'address' => 'House 4, Road 2, Dhanmondi', 'payment_method' => 'cod', 'delivery_zone' => 'dhaka', 'lines' => [['item_id' => $this->pen['id'], 'quantity_milli' => 1000]],
        ])->assertCreated()->json('data');

        return $this->asToken($this->staff)->postJson(($this->api)("orders/{$order['id']}/confirm"), ['base_version' => $order['version']])->assertOk()->json('data');
    };
    $this->addSteadfast = fn (array $extra = [], ?string $token = null) => $this->asToken($token ?? $this->admin)->postJson(($this->couriersApi)(), [
        'driver' => 'steadfast', 'name' => 'Steadfast', 'credentials' => ['api_key' => 'sf-api-key-1', 'secret_key' => 'sf-secret-1'], ...$extra,
    ]);
    $this->book = fn (array $order, string $courierId, ?string $token = null) => $this->asToken($token ?? $this->staff)
        ->postJson(($this->api)("orders/{$order['id']}/book"), ['base_version' => $order['version'], 'courier_id' => $courierId]);
    $this->notice = fn (array $courier, string $token, array $body) => $this->withToken($token)
        ->postJson("/api/couriers/{$this->w->c1->id}/{$courier['id']}/webhook", ['notification_type' => 'delivery_status', ...$body]);
    $this->booked = fn (string $status = 'in_review') => Http::response(['status' => 200, 'message' => 'Consignment has been created successfully.', 'consignment' => [
        'consignment_id' => 1424107, 'invoice' => 'x', 'tracking_code' => '15BAEB8A', 'cod_amount' => 290, 'status' => $status]]);
});

it('keeps a courier account\'s keys encrypted and never shows them; the webhook token only once', function () {
    $made = ($this->addSteadfast)()->assertCreated()->json('data');
    expect($made)->toMatchArray(['driver' => 'steadfast', 'name' => 'Steadfast', 'is_active' => true, 'version' => 1])
        ->and($made['webhook_url'])->toEndWith("/api/couriers/{$this->w->c1->id}/{$made['id']}/webhook")
        ->and(strlen($made['webhook_token']))->toBe(48)
        ->and(json_encode($made))->not->toContain('sf-secret-1');

    $raw = DB::connection(Courier::inTenantOf($this->w->c1)->getModel()->getConnectionName())->table('online_order_couriers')->where('id', $made['id'])->first();
    expect($raw->credentials)->not->toContain('sf-secret-1')
        ->and($raw->webhook_token_hash)->toBe(hash('sha256', $made['webhook_token']));

    $list = $this->asToken($this->admin)->getJson(($this->couriersApi)())->assertOk();
    expect($list->json('data.0'))->not->toHaveKey('webhook_token')
        ->and(json_encode($list->json()))->not->toContain('sf-api-key-1')
        ->and($list->json('meta.drivers'))->toBe([['key' => 'steadfast', 'fields' => ['api_key', 'secret_key']]]);

    // A new token replaces the old one; switched off; every change audited without the keys.
    $changed = $this->asToken($this->admin)->patchJson(($this->couriersApi)()."/{$made['id']}", ['base_version' => 1, 'new_token' => true, 'is_active' => false])->assertOk()->json('data');
    expect($changed['webhook_token'])->not->toBe($made['webhook_token'])->and($changed['is_active'])->toBeFalse();
    $this->asToken($this->admin)->patchJson(($this->couriersApi)()."/{$made['id']}", ['base_version' => 1, 'name' => 'Old'])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    expect(AuditLog::query()->whereIn('action', ['online_orders.courier_added', 'online_orders.courier_changed'])->count())->toBe(2)
        ->and(json_encode(AuditLog::query()->where('action', 'like', 'online_orders.courier_%')->get()->toArray()))->not->toContain('sf-secret-1');

    // Checks: every key, no unknown ones, a courier the platform knows, no unknown fields.
    ($this->addSteadfast)(['credentials' => ['api_key' => 'only-one']])->assertUnprocessable()->assertJsonValidationErrors('credentials');
    ($this->addSteadfast)(['credentials' => ['api_key' => 'a', 'secret_key' => 'b', 'extra' => 'c']])->assertUnprocessable()->assertJsonValidationErrors('credentials');
    ($this->addSteadfast)(['driver' => 'pigeon'])->assertUnprocessable()->assertJsonValidationErrors('driver');
    ($this->addSteadfast)(['organization_id' => $this->w->c2->id])->assertUnprocessable();

    // The rule: no couriers for this company.
    ruleService()->set(app(RuleTargets::class)->organization($this->w->c1->fresh()), 'online_orders.couriers', RuleMode::Set, [], 'Test setup', trusted: true);
    ($this->addSteadfast)()->assertUnprocessable()->assertJsonPath('code', 'courier_not_allowed');
});

it('books a confirmed order with the courier: tracking code, cash left to collect, stock out, shipped', function () {
    $courier = ($this->addSteadfast)()->json('data');
    $order = ($this->confirmed)();
    Http::fake([STEADFAST.'/create_order' => ($this->booked)()]);

    $shipped = ($this->book)($order, $courier['id'])->assertOk()->json('data');
    expect($shipped)->toMatchArray(['status' => 'shipped', 'courier' => 'Steadfast', 'tracking' => '15BAEB8A', 'courier_id' => $courier['id'],
        'courier_ref' => '1424107', 'courier_status' => 'booked']);
    Http::assertSent(fn (HttpRequest $request) => $request->url() === STEADFAST.'/create_order'
        && $request->header('Api-Key') === ['sf-api-key-1'] && $request->header('Secret-Key') === ['sf-secret-1']
        && $request['invoice'] === $order['number'] && $request['cod_amount'] === '290.00' && $request['recipient_phone'] === '01712345678');
    expect(AuditLog::query()->where('action', 'online_orders.courier_booked')->count())->toBe(1);

    // Booked once: the order has gone out.
    ($this->book)($shipped, $courier['id'])->assertStatus(409)->assertJsonPath('code', 'wrong_status');
    Http::assertSentCount(1);
});

it('leaves the order as it was when the courier refuses, cannot be reached or does not take the keys', function () {
    $courier = ($this->addSteadfast)()->json('data');
    $order = ($this->confirmed)();

    Http::fake([STEADFAST.'/create_order' => Http::sequence()
        ->push(['status' => 400, 'errors' => ['recipient_address' => ['The recipient address field is required.']]], 422)
        ->push(['message' => 'Unauthenticated.'], 401)
        ->pushFailedConnection()]);
    ($this->book)($order, $courier['id'])->assertUnprocessable()->assertJsonPath('code', 'courier_refused')
        ->assertJsonFragment(['message' => 'The courier refused the booking: The recipient address field is required. Fix it on the order and try again.']);
    ($this->book)($order, $courier['id'])->assertUnprocessable()->assertJsonPath('code', 'courier_bad_credentials');
    ($this->book)($order, $courier['id'])->assertStatus(503)->assertJsonPath('code', 'courier_unreachable');

    expect($this->asToken($this->staff)->getJson(($this->api)("orders/{$order['id']}"))->json('data'))->toMatchArray(['status' => 'confirmed', 'version' => $order['version'], 'courier_ref' => null]);

    // A switched-off account, an old version, a draft.
    $this->asToken($this->admin)->patchJson(($this->couriersApi)()."/{$courier['id']}", ['base_version' => 1, 'is_active' => false])->assertOk();
    ($this->book)($order, $courier['id'])->assertStatus(409)->assertJsonPath('code', 'courier_off');
});

it('hears the courier through its own token: delivered completes the order, other news only shows', function () {
    $courier = ($this->addSteadfast)()->json('data');
    Http::fake([STEADFAST.'/create_order' => ($this->booked)()]);
    $first = ($this->book)(($this->confirmed)(), $courier['id'])->json('data');
    $second = ($this->book)(($this->confirmed)(), $courier['id'])->json('data');
    $token = $courier['webhook_token'];
    $second = Modules\OnlineOrders\Models\Order::inTenantOf($this->w->c1)->withoutGlobalScopes()->whereKey($second['id'])->first();
    $second->forceFill(['courier_ref' => '2000001'])->save();

    // Wrong token, no token, another account's address: one answer, nothing changes.
    ($this->notice)($courier, 'not-the-token', ['consignment_id' => 1424107, 'status' => 'delivered'])->assertUnauthorized();
    $this->postJson("/api/couriers/{$this->w->c1->id}/{$courier['id']}/webhook", ['consignment_id' => 1424107, 'status' => 'delivered'])->assertUnauthorized();
    $this->withToken($token)->postJson("/api/couriers/{$this->w->c2->id}/{$courier['id']}/webhook", ['notification_type' => 'delivery_status', 'consignment_id' => 1424107, 'status' => 'delivered'])->assertUnauthorized();

    // On hold, then delivered: the order completes by itself (nobody named), posted like any delivery;
    // the history says it in the company's language (Bangla here).
    ($this->notice)($courier, $token, ['consignment_id' => 1424107, 'status' => 'hold', 'tracking_message' => 'Customer not answering'])->assertOk()->assertJson(['status' => 'success']);
    ($this->notice)($courier, $token, ['consignment_id' => 1424107, 'status' => 'Delivered'])->assertOk();
    ($this->notice)($courier, $token, ['consignment_id' => 1424107, 'status' => 'Delivered'])->assertOk();
    $done = $this->asToken($this->staff)->getJson(($this->api)("orders/{$first['id']}"))->json('data');
    expect($done)->toMatchArray(['status' => 'delivered', 'courier_status' => 'delivered'])
        ->and(collect($done['history'])->pluck('note')->filter()->values()->all())->toBe(['কুরিয়ারে আটকে আছে: Customer not answering', 'কুরিয়ার: পৌঁছে দিয়েছে']);

    // Tracking news, unknown parcels: answered, nothing changes.
    ($this->notice)($courier, $token, ['notification_type' => 'tracking_update', 'consignment_id' => 2000001, 'tracking_message' => 'At hub'])->assertOk();
    ($this->notice)($courier, $token, ['consignment_id' => 999, 'status' => 'delivered'])->assertOk();

    // The rule off: delivered only shows; cancelled always only shows (staff take the goods back).
    ruleService()->set(app(RuleTargets::class)->organization($this->w->c1->fresh()), 'online_orders.courier_marks_delivered', RuleMode::Set, false, 'Test setup', trusted: true);
    ($this->notice)($courier, $token, ['consignment_id' => 2000001, 'status' => 'cancelled'])->assertOk();
    expect($this->asToken($this->staff)->getJson(($this->api)("orders/{$second->id}"))->json('data'))->toMatchArray(['status' => 'shipped', 'courier_status' => 'cancelled']);
    ($this->notice)($courier, $token, ['consignment_id' => 2000001, 'status' => 'delivered'])->assertOk();
    expect($this->asToken($this->staff)->getJson(($this->api)("orders/{$second->id}"))->json('data'))->toMatchArray(['status' => 'shipped', 'courier_status' => 'delivered']);

    // Online orders off for the company: 404, nothing kept.
    toggles()->disable($this->w->g1, 'online_orders', 'Test setup', confirm: true);
    ($this->notice)($courier, $token, ['consignment_id' => 2000001, 'status' => 'hold'])->assertNotFound();
});

it('asks the courier where a parcel stands, for those who manage orders', function () {
    $courier = ($this->addSteadfast)()->json('data');
    Http::fake([STEADFAST.'/create_order' => ($this->booked)(), STEADFAST.'/status_by_cid/1424107' => Http::response(['status' => 200, 'delivery_status' => 'pending'])]);
    $order = ($this->book)(($this->confirmed)(), $courier['id'])->json('data');

    $fresh = $this->asToken($this->staff)->postJson(($this->api)("orders/{$order['id']}/courier-status"))->assertOk()->json('data');
    expect($fresh)->toMatchArray(['status' => 'shipped', 'courier_status' => 'in_transit']);
    Http::assertSent(fn (HttpRequest $request) => $request->url() === STEADFAST.'/status_by_cid/1424107' && $request->method() === 'GET');

    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['online_orders.view'], 'Looker')), $this->w->c1);
    $this->asToken($viewer)->postJson(($this->api)("orders/{$order['id']}/courier-status"))->assertForbidden();

    $plain = ($this->confirmed)();
    $this->asToken($this->staff)->postJson(($this->api)("orders/{$plain['id']}/courier-status"))->assertStatus(409)->assertJsonPath('code', 'not_booked');
});

it('keeps courier accounts to the company, the partner and those allowed, and off with the module', function () {
    $courier = ($this->addSteadfast)()->json('data');
    $order = ($this->confirmed)();

    // Order desk staff can book but not see or change accounts; the accounts live at the company, not a branch.
    $this->asToken($this->staff)->getJson(($this->couriersApi)())->assertForbidden();
    ($this->addSteadfast)([], $this->staff)->assertForbidden();
    $this->asToken($this->admin)->getJson(($this->couriersApi)($this->w->b1))->assertNotFound();

    // Another company of the same group, and a company of another partner.
    foreach ([$this->w->c2, $this->w->c4] as $other) {
        $token = orgToken(staffWithRoles($other, makeRole($other, ['online_orders.view', 'online_orders.manage', 'online_orders.couriers'], 'Admin')), $other);
        expect($this->asToken($token)->getJson(($this->couriersApi)($other))->assertOk()->json('data'))->toBe([]);
        $this->asToken($token)->patchJson(($this->couriersApi)($other)."/{$courier['id']}", ['base_version' => 1, 'name' => 'Mine'])->assertNotFound();
        $this->asToken($token)->getJson(($this->couriersApi)($this->w->c1))->assertNotFound();
        $this->asToken($token)->postJson(($this->api)("orders/{$order['id']}/book", $other), ['base_version' => $order['version'], 'courier_id' => $courier['id']])->assertNotFound();
    }
    // A company cannot book with another company's account.
    $other = $this->asToken(orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['online_orders.couriers'], 'Admin')), $this->w->c2))
        ->postJson(($this->couriersApi)($this->w->c2), ['driver' => 'steadfast', 'name' => 'Theirs', 'credentials' => ['api_key' => 'k', 'secret_key' => 's']]);
    ($this->book)($order, $other->assertCreated()->json('data.id'))->assertNotFound()->assertJsonPath('code', 'courier_not_found');

    toggles()->disable($this->w->g1, 'online_orders', 'Test setup', confirm: true);
    $this->asToken($this->admin)->getJson(($this->couriersApi)())->assertForbidden();
});
