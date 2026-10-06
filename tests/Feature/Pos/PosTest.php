<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Offline\SyncOperation;
use Carbon\CarbonImmutable;
use Modules\Pos\Offline\SaleSync;
use Modules\Pos\Services\Pricing;

/*
 * POS-1: counters selling out of an Inventory warehouse, shifts with a
 * float and the cash counted (a difference beyond the rule reviewed by a
 * supervisor), sales priced from items with VAT inside the price, payments
 * and change, discounts within the cashier's limit, returns at what was
 * paid and at cost, posting, offline sales kept and marked, isolation.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2] as $group) {
        foreach (['inventory', 'pos', 'accounting'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    $role = fn (array $permissions, string $name) => staffWithRoles($this->w->c1, makeRole($this->w->c1, $permissions, $name));
    $this->keeperUser = $role(['inventory.view', 'inventory.manage', 'pos.manage', 'accounting.view', 'accounting.manage', 'accounting.tax'], 'Back office');
    $this->keeper = orgToken($this->keeperUser, $this->w->c1);
    $this->cashierUser = $role(['pos.view', 'pos.sell', 'inventory.view'], 'Cashier');
    $this->cashier = orgToken($this->cashierUser, $this->w->c1);
    $this->supervisorUser = $role(['pos.view', 'pos.sell', 'pos.supervise', 'inventory.view'], 'Supervisor');
    $this->supervisor = orgToken($this->supervisorUser, $this->w->c1);
    setUpBooks($this, $this->keeper, $this->w->c1)->assertCreated();
    $inv = fn (string $path) => "/api/organizations/{$this->w->c1->id}/inventory/{$path}";
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/pos/{$path}";

    $vat = $this->asToken($this->keeper)->postJson("/api/organizations/{$this->w->c1->id}/accounting/tax-codes", ['code' => 'V15', 'name' => ['en' => 'VAT 15%'], 'rate_bp' => 1500, 'kind' => 'standard', 'applies_to' => 'sales'])->assertCreated()->json('data');
    $pcs = $this->asToken($this->keeper)->postJson($inv('units'), ['code' => 'PCS', 'name' => ['en' => 'Pieces'], 'decimals' => 0])->assertCreated()->json('data');
    $this->shop = $this->asToken($this->keeper)->postJson($inv('warehouses'), ['code' => 'SHOP', 'name' => ['en' => 'Shop'], 'unit_id' => $this->w->c1->id])->assertCreated()->json('data');
    $item = fn (array $data) => $this->asToken($this->keeper)->postJson($inv('items'), ['unit_id' => $pcs['id'], 'kind' => 'stock', ...$data])->assertCreated()->json('data');
    $this->soap = $item(['sku' => 'SOAP', 'barcode' => '8901234567890', 'name' => ['en' => 'Soap'], 'sale_price_minor' => 11500, 'tax_code_id' => $vat['id']]);
    $this->pen = $item(['sku' => 'PEN', 'name' => ['en' => 'Pen'], 'sale_price_minor' => 1000]);
    $receipt = $this->asToken($this->keeper)->postJson($inv('documents'), ['type' => 'receipt', 'warehouse_id' => $this->shop['id'], 'document_date' => '2026-11-01', 'lines' => [
        ['item_id' => $this->soap['id'], 'quantity_milli' => 10000, 'unit_cost_minor' => 6000], ['item_id' => $this->pen['id'], 'quantity_milli' => 100000, 'unit_cost_minor' => 500],
    ]])->assertCreated()->json('data');
    $this->asToken($this->keeper)->postJson($inv("documents/{$receipt['id']}/post"), ['base_version' => $receipt['version']])->assertOk();

    $this->till = $this->asToken($this->keeper)->postJson(($this->api)('registers'), ['code' => 'T1', 'name' => ['en' => 'Till 1'], 'unit_id' => $this->w->c1->id,
        'warehouse_id' => $this->shop['id'], 'payment_methods' => ['cash', 'card']])->assertCreated()->json('data');
    $this->open = fn (int $float = 100000, ?string $token = null) => $this->asToken($token ?? $this->cashier)->postJson(($this->api)("registers/{$this->till['id']}/open"), ['opening_float_minor' => $float]);
    $this->sell = fn (array $lines, array $payments, ?string $op = null, ?string $token = null) => $this->asToken($token ?? $this->cashier)->postJson(($this->api)('sales'), [
        'op_id' => $op ?? (string) str()->ulid(), 'register_id' => $this->till['id'], 'lines' => $lines, 'payments' => $payments,
    ]);
    $this->row = fn (string $code) => collect($this->asToken($this->keeper)->getJson("/api/organizations/{$this->w->c1->id}/accounting/reports/trial-balance?as_of=2026-11-02")->json('data.rows'))->firstWhere('code', $code);
    $this->onHand = fn (array $item) => collect($this->asToken($this->keeper)->getJson($inv('stock'))->json('data'))->firstWhere('item_id', $item['id'])['quantity_milli'];
});

it('sets up counters and gives them a catalogue with prices, VAT and stock', function () {
    $this->asToken($this->keeper)->postJson(($this->api)('registers'), ['code' => 'T1', 'name' => ['en' => 'Again'], 'unit_id' => $this->w->c1->id, 'warehouse_id' => $this->shop['id'], 'payment_methods' => ['cash']])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->asToken($this->keeper)->postJson(($this->api)('registers'), ['code' => 'T2', 'name' => ['en' => 'Till 2'], 'unit_id' => $this->w->c1->id, 'warehouse_id' => '01HZNOTAWAREHOUSE000000000', 'payment_methods' => ['cash']])
        ->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
    $this->asToken($this->cashier)->postJson(($this->api)('registers'), ['code' => 'T3', 'name' => ['en' => 'Till 3'], 'unit_id' => $this->w->c1->id, 'warehouse_id' => $this->shop['id'], 'payment_methods' => ['cash']])->assertForbidden();

    $catalogue = collect($this->asToken($this->cashier)->getJson(($this->api)("registers/{$this->till['id']}/catalogue"))->assertOk()->json('data.items'))->keyBy('sku');
    expect($catalogue['SOAP'])->toMatchArray(['sale_price_minor' => 11500, 'tax_rate_bp' => 1500, 'on_hand_milli' => 10000, 'barcode' => '8901234567890'])
        ->and($catalogue['PEN']['tax_rate_bp'])->toBe(0);
});

it('sells within an open shift: VAT inside the price, change from cash, stock out, posted once', function () {
    ($this->sell)([['item_id' => $this->soap['id'], 'quantity_milli' => 1000]], [['method' => 'cash', 'amount_minor' => 11500]])->assertConflict()->assertJsonPath('code', 'no_open_session');
    ($this->open)()->assertCreated();
    ($this->open)()->assertConflict()->assertJsonPath('code', 'session_open');

    // Two soaps (115.00 each, VAT 15% inside) and three pens; paid 300.00 cash.
    $op = (string) str()->ulid();
    $sale = ($this->sell)([['item_id' => $this->soap['id'], 'quantity_milli' => 2000], ['item_id' => $this->pen['id'], 'quantity_milli' => 3000]], [['method' => 'cash', 'amount_minor' => 30000]], $op)
        ->assertCreated()->json('data');
    expect($sale)->toMatchArray(['kind' => 'sale', 'number' => 'R-T1-2026-000001', 'subtotal_minor' => 26000, 'tax_minor' => 3000, 'total_minor' => 26000, 'paid_minor' => 30000, 'change_minor' => 4000])
        ->and($sale['lines'][0])->toMatchArray(['tax_minor' => 3000, 'total_minor' => 23000]);
    expect(($this->sell)([['item_id' => $this->soap['id'], 'quantity_milli' => 2000]], [['method' => 'cash', 'amount_minor' => 30000]], $op)->json('data.id'))->toBe($sale['id'])
        ->and(($this->onHand)($this->soap))->toBe(8000);

    // Cash net of change; sales without VAT; VAT payable; cost of two soaps and three pens.
    expect(($this->row)('1110')['debit_minor'])->toBe(26000)
        ->and(($this->row)('4100')['credit_minor'])->toBe(23000)
        ->and(($this->row)('2130')['credit_minor'])->toBe(3000)
        ->and(($this->row)('5100')['debit_minor'])->toBe(12000 + 1500);

    ($this->sell)([['item_id' => $this->pen['id'], 'quantity_milli' => 1000]], [['method' => 'cash', 'amount_minor' => 500]])->assertUnprocessable()->assertJsonPath('code', 'underpaid');
    ($this->sell)([['item_id' => $this->pen['id'], 'quantity_milli' => 1000]], [['method' => 'card', 'amount_minor' => 2000]])->assertUnprocessable()->assertJsonPath('code', 'change_without_cash');
    ($this->sell)([['item_id' => $this->pen['id'], 'quantity_milli' => 1000]], [['method' => 'mobile', 'amount_minor' => 1000]])->assertUnprocessable()->assertJsonPath('code', 'method_not_taken');
    ($this->sell)([['item_id' => $this->pen['id'], 'quantity_milli' => 1500]], [['method' => 'cash', 'amount_minor' => 1500]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity_milli');
    ($this->sell)([['item_id' => $this->soap['id'], 'quantity_milli' => 9000]], [['method' => 'cash', 'amount_minor' => 200000]])->assertConflict()->assertJsonPath('code', 'insufficient_stock');

    // A discount above 10% of the sale needs a supervisor.
    ($this->sell)([['item_id' => $this->soap['id'], 'quantity_milli' => 1000, 'discount_minor' => 2000]], [['method' => 'cash', 'amount_minor' => 9500]])->assertForbidden()->assertJsonPath('code', 'discount_limit');
    ($this->sell)([['item_id' => $this->soap['id'], 'quantity_milli' => 1000, 'discount_minor' => 2000]], [['method' => 'card', 'amount_minor' => 9500]], null, $this->supervisor)->assertCreated()
        ->assertJsonPath('data.discount_minor', 2000)->assertJsonPath('data.total_minor', 9500);
    expect(AuditLog::query()->where('action', 'pos.sale_made')->count())->toBe(2);
});

it('takes goods back: a supervisor, what was paid, at cost, never more than sold', function () {
    ($this->open)()->assertCreated();
    $sale = ($this->sell)([['item_id' => $this->soap['id'], 'quantity_milli' => 2000]], [['method' => 'cash', 'amount_minor' => 23000]])->json('data');
    $line = $sale['lines'][0]['id'];
    $give = fn (int $quantity, ?string $token = null, array $extra = []) => $this->asToken($token ?? $this->supervisor)->postJson(($this->api)("sales/{$sale['id']}/return"), [
        'op_id' => (string) str()->ulid(), 'register_id' => $this->till['id'], 'reason' => 'Wrong size', 'lines' => [['line_id' => $line, 'quantity_milli' => $quantity]], ...$extra,
    ]);

    $give(1000, $this->cashier)->assertForbidden();
    $back = $give(1000)->assertCreated()->json('data');
    expect($back)->toMatchArray(['kind' => 'return', 'number' => 'RT-T1-2026-000001', 'total_minor' => 11500, 'tax_minor' => 1500, 'original_sale_id' => $sale['id']])
        ->and(($this->onHand)($this->soap))->toBe(9000);
    $give(2000)->assertUnprocessable()->assertJsonPath('code', 'return_too_much');
    $give(1000, null, ['payments' => [['method' => 'cash', 'amount_minor' => 100]]])->assertUnprocessable()->assertJsonValidationErrors('payments');

    expect(($this->row)('4100')['credit_minor'])->toBe(10000)
        ->and(($this->row)('5100')['debit_minor'])->toBe(6000)
        ->and($this->asToken($this->cashier)->getJson(($this->api)("sales/{$sale['id']}"))->json('data.returns'))->toBe(['RT-T1-2026-000001']);

    $this->travel(31)->days();
    // Tokens do not outlive a month: sign in again.
    $this->supervisor = orgToken($this->supervisorUser, $this->w->c1);
    $this->asToken($this->supervisor)->postJson(($this->api)("sales/{$sale['id']}/return"), ['op_id' => (string) str()->ulid(), 'register_id' => $this->till['id'], 'reason' => 'Late', 'lines' => [['line_id' => $line, 'quantity_milli' => 1000]]])
        ->assertConflict()->assertJsonPath('code', 'return_too_late');
});

it('closes a shift on the cash counted; a difference beyond the rule waits for a supervisor', function () {
    $session = ($this->open)(100000)->json('data');
    ($this->sell)([['item_id' => $this->soap['id'], 'quantity_milli' => 2000]], [['method' => 'cash', 'amount_minor' => 30000]])->assertCreated();
    ($this->sell)([['item_id' => $this->pen['id'], 'quantity_milli' => 5000]], [['method' => 'card', 'amount_minor' => 5000]])->assertCreated();
    $session = $this->asToken($this->cashier)->getJson(($this->api)("sessions/{$session['id']}"))->assertOk()->json('data');
    expect($session['report'])->toMatchArray(['sales' => 2, 'sales_minor' => 28000, 'expected_cash_minor' => 123000])
        ->and($session['report']['methods'])->toMatchArray(['cash' => 23000, 'card' => 5000]);

    // 1,229.00 counted: 1.00 short, and no difference is allowed by default.
    $closed = $this->asToken($this->cashier)->postJson(($this->api)("sessions/{$session['id']}/close"), ['base_version' => $session['version'], 'counted_cash_minor' => 122900])->assertOk()->json('data');
    expect($closed)->toMatchArray(['status' => 'pending_review', 'expected_cash_minor' => 123000, 'variance_minor' => -100]);
    ($this->sell)([['item_id' => $this->pen['id'], 'quantity_milli' => 1000]], [['method' => 'cash', 'amount_minor' => 1000]])->assertConflict()->assertJsonPath('code', 'no_open_session');
    $this->asToken($this->cashier)->postJson(($this->api)("sessions/{$session['id']}/review"), ['base_version' => $closed['version'], 'note' => 'Looks fine'])->assertForbidden();
    $this->asToken($this->supervisor)->postJson(($this->api)("sessions/{$session['id']}/review"), ['base_version' => $closed['version'], 'note' => 'Counted twice, short by a coin'])
        ->assertOk()->assertJsonPath('data.status', 'closed');
    expect(($this->row)('5900')['debit_minor'])->toBe(100);

    // Within an allowed difference of 5.00 the next shift just closes.
    trustedOrgRule($this->w->c1, 'pos.cash_variance_allowed', ['amount' => 500, 'currency' => 'BDT']);
    $next = ($this->open)(50000)->json('data');
    $this->asToken($this->cashier)->postJson(($this->api)("sessions/{$next['id']}/close"), ['base_version' => $next['version'], 'counted_cash_minor' => 50200])
        ->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.variance_minor', 200);
});

it('keeps a sale made offline as it was, marking what no longer holds', function () {
    $session = ($this->open)()->json('data');
    // As the sync endpoint does: each operation runs in the cashier's own context.
    $apply = function (SyncOperation $operation) {
        $this->asToken($this->cashier)->getJson(($this->api)('registers'))->assertOk();

        return app(SaleSync::class)->apply($operation);
    };
    $operation = fn (string $op, array $lines, ?string $sessionId) => new SyncOperation($op, 'pos.sale', SyncOperation::CREATE, null, null,
        ['register_id' => $this->till['id'], 'session_id' => $sessionId, 'lines' => $lines, 'payments' => [['method' => 'cash', 'amount_minor' => 200000]]],
        CarbonImmutable::parse('2026-11-02 03:00:00', 'UTC'), $this->cashierUser, $this->w->c1);

    $result = $apply($operation('01HZOFFLINE000000000000001', [['item_id' => $this->soap['id'], 'quantity_milli' => 12000]], $session['id']));
    expect($result->status)->toBe('applied');
    $sale = $this->asToken($this->cashier)->getJson(($this->api)("sales/{$result->recordId}"))->json('data');
    expect($sale)->toMatchArray(['offline' => true, 'review_reason' => 'negative_stock'])->and(($this->onHand)($this->soap))->toBe(-2000);
    $again = $apply($operation('01HZOFFLINE000000000000001', [['item_id' => $this->soap['id'], 'quantity_milli' => 12000]], $session['id']));
    expect($again->recordId)->toBe($result->recordId);

    // Shift closed meanwhile: still kept, marked.
    $this->asToken($this->cashier)->postJson(($this->api)("sessions/{$session['id']}/close"), ['base_version' => $this->asToken($this->cashier)->getJson(($this->api)("sessions/{$session['id']}"))->json('data.version'), 'counted_cash_minor' => 0])->assertOk();
    $late = $apply($operation('01HZOFFLINE000000000000002', [['item_id' => $this->pen['id'], 'quantity_milli' => 1000]], $session['id']));
    expect($late->status)->toBe('applied')
        ->and($this->asToken($this->cashier)->getJson(($this->api)("sales/{$late->recordId}"))->json('data.review_reason'))->toBe('late_session');
    expect(collect($this->asToken($this->supervisor)->getJson('/api/attention')->json('data'))->pluck('key')->all())->toContain('pos.sales_review', 'pos.shifts_review');

    trustedOrgRule($this->w->c1, 'pos.offline_sales', false);
    expect($apply($operation('01HZOFFLINE000000000000003', [['item_id' => $this->pen['id'], 'quantity_milli' => 1000]], $session['id']))->status)->toBe('quarantined');
});

it('keeps counters and sales to the company, and off when the module is off', function () {
    ($this->open)()->assertCreated();
    $sale = ($this->sell)([['item_id' => $this->pen['id'], 'quantity_milli' => 1000]], [['method' => 'cash', 'amount_minor' => 1000]])->json('data');
    $c2 = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['pos.view', 'pos.sell', 'pos.supervise'], 'C2 till')), $this->w->c2);
    expect($this->asToken($c2)->getJson(($this->api)('registers', $this->w->c2))->json('data'))->toBe([]);
    $this->asToken($c2)->getJson(($this->api)("sales/{$sale['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($c2)->postJson(($this->api)('sales', $this->w->c2), ['op_id' => 'x1', 'register_id' => $this->till['id'], 'lines' => [['item_id' => $this->pen['id'], 'quantity_milli' => 1000]], 'payments' => [['method' => 'cash', 'amount_minor' => 1000]]])
        ->assertNotFound();

    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view'], 'Not a cashier')), $this->w->c1);
    $this->asToken($viewer)->getJson(($this->api)('sales'))->assertForbidden();
    toggles()->disable($this->w->g1, 'pos', 'Test setup', confirm: true);
    $this->asToken($this->cashier)->getJson(($this->api)('registers'))->assertForbidden();
});

it('prices with integers: VAT inside or on top, change from cash only', function () {
    expect(Pricing::sale([['quantity_milli' => 2000, 'unit_price_minor' => 11500, 'discount_minor' => 0, 'tax_rate_bp' => 1500]], true))->toMatchArray(['subtotal' => 23000, 'tax' => 3000, 'total' => 23000])
        ->and(Pricing::sale([['quantity_milli' => 2000, 'unit_price_minor' => 10000, 'discount_minor' => 1000, 'tax_rate_bp' => 1500]], false))->toMatchArray(['subtotal' => 20000, 'discount' => 1000, 'tax' => 2850, 'total' => 21850])
        ->and(Pricing::sale([['quantity_milli' => 1250, 'unit_price_minor' => 8000, 'discount_minor' => 0, 'tax_rate_bp' => 0]], true)['total'])->toBe(10000)
        ->and(Pricing::settle(1000, [['method' => 'card', 'amount_minor' => 500], ['method' => 'cash', 'amount_minor' => 1000]]))->toBe(['paid' => 1500, 'change' => 500, 'cash' => 1000])
        ->and(Pricing::settle(1000, [['method' => 'cash', 'amount_minor' => 999]]))->toBeNull()
        ->and(Pricing::discountShare(2000, 11500))->toBe(1739)
        ->and(Pricing::part(23000, 1000, 2000))->toBe(11500);
});

it('reports takings by day, hour, cashier, method and item to supervisors only, returns counted against them', function () {
    ($this->open)()->assertCreated();
    // Two soaps cash (230.00, 300.00 handed over: 70.00 change) and ten pens by card (100.00).
    $sale = ($this->sell)([['item_id' => $this->soap['id'], 'quantity_milli' => 2000]], [['method' => 'cash', 'amount_minor' => 30000]])->assertCreated()->json('data');
    ($this->sell)([['item_id' => $this->pen['id'], 'quantity_milli' => 10000]], [['method' => 'card', 'amount_minor' => 10000]])->assertCreated();
    $line = $this->asToken($this->supervisor)->getJson(($this->api)("sales/{$sale['id']}"))->json('data.lines.0.id');
    $this->asToken($this->supervisor)->postJson(($this->api)("sales/{$sale['id']}/return"), ['op_id' => (string) str()->ulid(), 'register_id' => $this->till['id'],
        'reason' => 'Wrong one', 'lines' => [['line_id' => $line, 'quantity_milli' => 1000]]])->assertCreated();

    $this->asToken($this->cashier)->getJson(($this->api)('reports'))->assertForbidden();
    $report = $this->asToken($this->supervisor)->getJson(($this->api)('reports?from=2026-11-02&to=2026-11-02'))->assertOk()->json('data');
    expect($report['totals'])->toMatchArray(['sales' => 33000, 'sales_count' => 2, 'returns' => 11500, 'returns_count' => 1, 'net' => 21500, 'average' => 16500])
        ->and(collect($report['methods'])->pluck('amount', 'method')->all())->toEqual(['cash' => 23000 - 11500, 'card' => 10000])
        ->and(collect($report['items'])->firstWhere('sku', 'SOAP'))->toMatchArray(['quantity_milli' => 1000, 'amount' => 11500])
        ->and($report['cashiers'])->toHaveCount(2)
        ->and($report['days'])->toBe([['date' => '2026-11-02', 'amount' => 21500, 'count' => 2]]);
    // Every sale falls in one hour of the company's day.
    expect(collect($report['hours'])->sum('count'))->toBe(2)->and($report['hours'])->toHaveCount(24);

    // A year at most; another company's counter is not found.
    $this->asToken($this->supervisor)->getJson(($this->api)('reports?from=2025-01-01&to=2026-11-02'))->assertUnprocessable()->assertJsonValidationErrors('to');
    $other = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['pos.view', 'pos.supervise'], 'Elsewhere')), $this->w->c2);
    $this->asToken($other)->getJson(($this->api)("reports?register_id={$this->till['id']}", $this->w->c2))->assertNotFound();
    expect($this->asToken($other)->getJson(($this->api)('reports', $this->w->c2))->assertOk()->json('data.totals.sales_count'))->toBe(0);
});
