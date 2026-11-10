<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\RuleTargets;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Modules\Accounting\Models\JournalLine;

/*
 * SC-6: the courier says it delivered a cash-on-delivery parcel, so it holds
 * what the customer owed: a "courier" payment into the courier clearing
 * account (rule online_orders.courier_collects_cod). A payout settles the
 * payments it names: what arrived and where, the courier's charge, and a
 * difference only with a reason; into the books, once, never changed.
 * Permission, module, company and partner boundaries.
 */

const STEADFAST_API = 'https://portal.packzy.com/api/v1';

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
    $this->cashier = $role($this->w->c1, ['online_orders.view', 'online_orders.courier_payouts'], 'Accountant');
    setUpBooks($this, $keeper, $this->w->c1)->assertCreated();
    $inv = fn (string $path) => "/api/organizations/{$this->w->c1->id}/inventory/{$path}";
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->b1)->id."/online-orders/{$path}";
    $this->payouts = fn (string $path = '', $company = null) => '/api/organizations/'.($company ?? $this->w->c1)->id.'/online-orders/courier-payouts'.($path === '' ? '' : "/{$path}");

    $pcs = $this->asToken($keeper)->postJson($inv('units'), ['code' => 'PCS', 'name' => ['en' => 'Pieces'], 'decimals' => 0])->assertCreated()->json('data');
    $store = $this->asToken($keeper)->postJson($inv('warehouses'), ['code' => 'WEB', 'name' => ['en' => 'Web store'], 'unit_id' => $this->w->c1->id])->assertCreated()->json('data');
    $pen = $this->asToken($keeper)->postJson($inv('items'), ['unit_id' => $pcs['id'], 'kind' => 'stock', 'sku' => 'PEN', 'name' => ['en' => 'Pen'], 'sale_price_minor' => 23000])->assertCreated()->json('data');
    $receipt = $this->asToken($keeper)->postJson($inv('documents'), ['type' => 'receipt', 'warehouse_id' => $store['id'], 'document_date' => '2026-11-01', 'lines' => [
        ['item_id' => $pen['id'], 'quantity_milli' => 100000, 'unit_cost_minor' => 10000],
    ]])->assertCreated()->json('data');
    $this->asToken($keeper)->postJson($inv("documents/{$receipt['id']}/post"), ['base_version' => $receipt['version']])->assertOk();
    ruleService()->set(app(RuleTargets::class)->organization($this->w->c1->fresh()), 'online_orders.delivery_zones', RuleMode::Set, [
        ['code' => 'dhaka', 'name' => ['en' => 'Inside Dhaka'], 'charge_minor' => 6000],
    ], 'Test setup', trusted: true);

    $made = $this->asToken($this->staff)->postJson('/api/organizations/'.$this->w->c1->id.'/online-orders/couriers', [
        'driver' => 'steadfast', 'name' => 'Steadfast', 'credentials' => ['api_key' => 'k', 'secret_key' => 's'],
    ])->assertCreated()->json('data');
    $this->courier = $made;
    $this->token = $made['webhook_token'];

    // One fake (fakes pile up, the first wins): the courier answers with the consignment number set for the booking.
    Http::fake([STEADFAST_API.'/create_order' => fn () => Http::response(['status' => 200, 'consignment' => ['consignment_id' => $this->nextCid, 'tracking_code' => "T{$this->nextCid}", 'status' => 'in_review']])]);
    // A cash-on-delivery order of 290 taka (230 + 60), booked with the courier as consignment $cid.
    $this->booked = function (int $cid) use ($store, $pen) {
        $order = $this->asToken($this->staff)->postJson(($this->api)('orders'), [
            'op_id' => (string) str()->ulid(), 'warehouse_id' => $store['id'], 'customer_name' => 'Karim Uddin', 'customer_phone' => '01712345678',
            'address' => 'House 4, Road 2, Dhanmondi', 'payment_method' => 'cod', 'delivery_zone' => 'dhaka', 'lines' => [['item_id' => $pen['id'], 'quantity_milli' => 1000]],
        ])->assertCreated()->json('data');
        $order = $this->asToken($this->staff)->postJson(($this->api)("orders/{$order['id']}/confirm"), ['base_version' => $order['version']])->assertOk()->json('data');
        $this->nextCid = $cid;

        return $this->asToken($this->staff)->postJson(($this->api)("orders/{$order['id']}/book"), ['base_version' => $order['version'], 'courier_id' => $this->courier['id']])->assertOk()->json('data');
    };
    $this->says = fn (int $cid, string $status) => $this->withToken($this->token)
        ->postJson("/api/couriers/{$this->w->c1->id}/{$this->courier['id']}/webhook", ['notification_type' => 'delivery_status', 'consignment_id' => $cid, 'status' => $status])->assertOk();
    $this->balance = fn (string $code) => (int) JournalLine::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)
        ->where('organization_id', $this->w->c1->id)->where('account_id', accountId($this->w->c1, $code))->get()->sum(fn ($line) => $line->debit_minor - $line->credit_minor);
    $this->pay = fn (array $body, ?string $token = null) => $this->asToken($token ?? $this->cashier)->postJson(($this->payouts)($this->courier['id']), [
        'op_id' => (string) str()->ulid(), 'method' => 'bank', 'paid_on' => '2026-11-10', ...$body,
    ]);
});

it('records what the courier collected when it says delivered, once, and lists it as owed', function () {
    $first = ($this->booked)(5001);
    $second = ($this->booked)(5002);
    ($this->says)(5001, 'delivered');
    ($this->says)(5001, 'delivered');
    ($this->says)(5002, 'hold');

    $order = $this->asToken($this->staff)->getJson(($this->api)("orders/{$first['id']}"))->assertOk()->json('data');
    expect($order)->toMatchArray(['status' => 'delivered', 'paid_minor' => 29000, 'due_minor' => 0])
        ->and(collect($order['payments'])->where('method', 'courier')->count())->toBe(1);
    // In the books: the customer's debt moved to the courier (both on 1140 in the general chart).
    expect(($this->balance)('1140'))->toBe(29000);

    $list = $this->asToken($this->cashier)->getJson(($this->payouts)())->assertOk()->json('data');
    expect($list[0]['owed'])->toBe([['currency' => 'BDT', 'amount_minor' => 29000, 'count' => 1]]);
    $owed = $this->asToken($this->cashier)->getJson(($this->payouts)($this->courier['id']))->assertOk()->json('data.owed');
    expect($owed)->toHaveCount(1)->and($owed[0])->toMatchArray(['order_id' => $first['id'], 'number' => $first['number'], 'courier_ref' => '5001', 'amount_minor' => 29000]);

    // Rule off: the courier's "delivered" records nothing; staff record the payment by hand.
    ruleService()->set(app(RuleTargets::class)->organization($this->w->c1->fresh()), 'online_orders.courier_collects_cod', RuleMode::Set, false, 'Test', trusted: true);
    ($this->says)(5002, 'delivered');
    expect($this->asToken($this->staff)->getJson(($this->api)("orders/{$second['id']}"))->json('data.due_minor'))->toBe(29000);
});

it('settles the owed payments with a payout: bank, courier charge and a difference with its reason', function () {
    ($this->booked)(6001);
    ($this->booked)(6002);
    ($this->says)(6001, 'delivered');
    ($this->says)(6002, 'delivered');
    $owed = collect($this->asToken($this->cashier)->getJson(($this->payouts)($this->courier['id']))->json('data.owed'))->pluck('id')->all();

    // 580 collected; 550 arrived, 25 charge: 5 short needs a reason.
    ($this->pay)(['payment_ids' => $owed, 'received_minor' => 55000, 'fee_minor' => 2500])->assertUnprocessable()->assertJsonPath('code', 'difference_needs_reason');
    $body = ['op_id' => (string) str()->ulid(), 'payment_ids' => $owed, 'received_minor' => 55000, 'fee_minor' => 2500, 'reference' => 'SF-PAY-77', 'reason' => 'Return charge on one parcel'];
    $payout = ($this->pay)($body)->assertCreated()->json('data');
    expect($payout)->toMatchArray(['collected_minor' => 58000, 'received_minor' => 55000, 'fee_minor' => 2500, 'difference_minor' => 500, 'payments_count' => 2, 'posted' => true, 'method' => 'bank']);
    // Sent again: the same payout, nothing more.
    expect(($this->pay)($body)->assertCreated()->json('data.id'))->toBe($payout['id']);

    expect(($this->balance)('1140'))->toBe(0)
        ->and(($this->balance)('1120'))->toBe(55000)
        ->and(($this->balance)('5600'))->toBe(2500)
        ->and(($this->balance)('5900'))->toBe(500);
    $detail = $this->asToken($this->cashier)->getJson(($this->payouts)($this->courier['id']))->json('data');
    expect($detail['owed'])->toBe([])->and($detail['payouts'])->toHaveCount(1)->and($detail['payouts'][0]['recorded_by'])->not->toBeNull();
    expect(AuditLog::query()->where('action', 'online_orders.courier_payout_recorded')->count())->toBe(1);

    // Settled payments cannot be settled again; an even payout needs no reason.
    ($this->pay)(['payment_ids' => [$owed[0]], 'received_minor' => 29000])->assertStatus(409)->assertJsonPath('code', 'payments_not_owed');
    ($this->booked)(6003);
    ($this->says)(6003, 'delivered');
    $next = collect($this->asToken($this->cashier)->getJson(($this->payouts)($this->courier['id']))->json('data.owed'))->pluck('id')->all();
    ($this->pay)(['payment_ids' => $next, 'received_minor' => 29000, 'method' => 'mobile'])->assertCreated()->assertJsonPath('data.difference_minor', 0);
});

it('checks the payout: fields, payments of this courier only, no unknown fields', function () {
    ($this->booked)(7001);
    ($this->says)(7001, 'delivered');
    $owed = collect($this->asToken($this->cashier)->getJson(($this->payouts)($this->courier['id']))->json('data.owed'))->pluck('id')->all();

    ($this->pay)(['payment_ids' => [], 'received_minor' => 1])->assertUnprocessable()->assertJsonValidationErrors('payment_ids');
    ($this->pay)(['payment_ids' => $owed, 'received_minor' => -5])->assertUnprocessable()->assertJsonValidationErrors('received_minor');
    ($this->pay)(['payment_ids' => $owed, 'received_minor' => 29000, 'method' => 'online'])->assertUnprocessable()->assertJsonValidationErrors('method');
    ($this->pay)(['payment_ids' => $owed, 'received_minor' => 29000, 'paid_on' => '2026-12-01'])->assertUnprocessable()->assertJsonValidationErrors('paid_on');
    ($this->pay)(['payment_ids' => $owed, 'received_minor' => 29000, 'organization_id' => $this->w->c2->id])->assertUnprocessable();
    ($this->pay)(['payment_ids' => [(string) str()->ulid()], 'received_minor' => 29000])->assertStatus(409)->assertJsonPath('code', 'payments_not_owed');

    // Another courier account of the same company cannot settle this one's payments.
    $other = $this->asToken($this->staff)->postJson('/api/organizations/'.$this->w->c1->id.'/online-orders/couriers', [
        'driver' => 'steadfast', 'name' => 'Steadfast 2', 'credentials' => ['api_key' => 'k2', 'secret_key' => 's2'],
    ])->assertCreated()->json('data');
    $this->asToken($this->cashier)->postJson(($this->payouts)($other['id']), ['op_id' => (string) str()->ulid(), 'payment_ids' => $owed, 'method' => 'bank', 'received_minor' => 29000, 'paid_on' => '2026-11-10'])
        ->assertStatus(409)->assertJsonPath('code', 'payments_not_owed');
});

it('needs the payouts permission at the company and Online orders on', function () {
    $this->asToken($this->staff)->getJson(($this->payouts)())->assertForbidden();
    ($this->pay)(['payment_ids' => [(string) str()->ulid()], 'received_minor' => 1], $this->staff)->assertForbidden();
    // Only at the company: a branch address is not where couriers are.
    $this->asToken($this->cashier)->getJson(($this->payouts)('', $this->w->b1))->assertNotFound();

    toggles()->disable($this->w->g1, 'online_orders', 'Test', confirm: true);
    $this->asToken($this->cashier)->getJson(($this->payouts)())->assertForbidden();
});

it('keeps each company to its own couriers and payouts', function () {
    ($this->booked)(8001);
    ($this->says)(8001, 'delivered');
    $owed = collect($this->asToken($this->cashier)->getJson(($this->payouts)($this->courier['id']))->json('data.owed'))->pluck('id')->all();

    $c2 = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['online_orders.view', 'online_orders.courier_payouts'], 'C2 accountant')), $this->w->c2);
    $c4 = orgToken(staffWithRoles($this->w->c4, makeRole($this->w->c4, ['online_orders.view', 'online_orders.courier_payouts'], 'C4 accountant')), $this->w->c4);
    foreach ([$c2, $c4] as $token) {
        $this->asToken($token)->getJson(($this->payouts)())->assertNotFound();
        $this->asToken($token)->getJson(($this->payouts)($this->courier['id']))->assertNotFound();
    }
    expect($this->asToken($c2)->getJson(($this->payouts)('', $this->w->c2))->assertOk()->json('data'))->toBe([]);
    $this->asToken($c2)->getJson(($this->payouts)($this->courier['id'], $this->w->c2))->assertNotFound();
    $this->asToken($c2)->postJson(($this->payouts)($this->courier['id'], $this->w->c2), ['op_id' => (string) str()->ulid(), 'payment_ids' => $owed, 'method' => 'bank', 'received_minor' => 29000, 'paid_on' => '2026-11-10'])
        ->assertNotFound();
    expect($this->asToken($this->cashier)->getJson(($this->payouts)($this->courier['id']))->json('data.owed'))->toHaveCount(1);
});
