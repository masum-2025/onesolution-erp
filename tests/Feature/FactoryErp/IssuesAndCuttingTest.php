<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * FAC-2a: materials from the store to an order on the floor and back
 * (stock out and in through Inventory, work in progress in the books),
 * the cutting table (lays by size and colour, the allowance, voiding), and
 * who may do what: permission, the order's state, other companies, the
 * module off.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    foreach ([$this->w->c1, $this->w->c2] as $company) {
        $company->forceFill(['sector_key' => 'factory'])->save();
    }
    foreach (['inventory', 'accounting', 'crm', 'factory_erp'] as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);
    $this->fac = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/factory/{$path}";
    $this->inv = fn (string $path) => "/api/organizations/{$this->w->c1->id}/inventory/{$path}";

    setUpBooks($this, $this->token, $this->w->c1, ['template' => 'factory'])->assertCreated();
    $this->keeper = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['inventory.view', 'inventory.manage', 'factory_erp.view', 'factory_erp.issue'], 'Store keeper')), $this->w->c1);
    $this->cutter = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['factory_erp.view', 'factory_erp.produce'], 'Cutting master')), $this->w->c1);
    $post = fn (string $path, array $data) => ($this->as)($this->keeper)->postJson(($this->inv)($path), $data)->assertCreated()->json('data');
    $kg = $post('units', ['code' => 'KG', 'name' => ['en' => 'Kilogram'], 'decimals' => 3]);
    $pcs = $post('units', ['code' => 'PCS', 'name' => ['en' => 'Pieces'], 'decimals' => 0]);
    $this->store = $post('warehouses', ['code' => 'MAIN', 'name' => ['en' => 'Main store'], 'unit_id' => $this->w->c1->id]);
    $this->fabric = $post('items', ['sku' => 'FAB-JERSEY', 'name' => ['en' => 'Single jersey fabric'], 'unit_id' => $kg['id'], 'kind' => 'stock']);
    $this->button = $post('items', ['sku' => 'BTN-12L', 'name' => ['en' => 'Button 12L'], 'unit_id' => $pcs['id'], 'kind' => 'stock']);
    $this->thread = $post('items', ['sku' => 'THR-40', 'name' => ['en' => 'Thread 40/2'], 'unit_id' => $pcs['id'], 'kind' => 'stock']);
    // 300 kg of fabric at 450.00 a kg, 5,000 buttons at 0.80.
    $receipt = ($this->as)($this->keeper)->postJson(($this->inv)('documents'), ['type' => 'receipt', 'warehouse_id' => $this->store['id'], 'document_date' => '2026-11-01', 'lines' => [
        ['item_id' => $this->fabric['id'], 'quantity_milli' => 300000, 'unit_cost_minor' => 45000],
        ['item_id' => $this->button['id'], 'quantity_milli' => 5000000, 'unit_cost_minor' => 80],
    ]])->assertCreated()->json('data');
    ($this->as)($this->keeper)->postJson(($this->inv)("documents/{$receipt['id']}/post"), ['base_version' => $receipt['version']])->assertOk();

    $buyer = ($this->as)()->postJson(($this->fac)('buyers'), ['name' => 'Nordic Wear AB'])->assertCreated()->json('data');
    $style = ($this->as)()->postJson(($this->fac)('styles'), ['buyer_id' => $buyer['id'], 'code' => 'NW-2611', 'name' => 'Basic polo', 'sizes' => ['M', 'L', 'XL'], 'colors' => ['Navy', 'Red']])
        ->assertCreated()->json('data');
    // Fabric 2.400 kg a dozen +5%, two buttons a piece (24 a dozen).
    ($this->as)()->putJson(($this->fac)("styles/{$style['id']}/bom"), ['base_version' => 1, 'lines' => [
        ['item_id' => $this->fabric['id'], 'consumption_milli' => 2400, 'wastage_bp' => 500],
        ['item_id' => $this->button['id'], 'consumption_milli' => 24000, 'wastage_bp' => 0],
    ]])->assertOk();
    // 600 M Navy + 240 XL Red = 840 pieces: 176.400 kg of fabric, 1,680 buttons.
    $this->order = ($this->as)()->postJson(($this->fac)('orders'), ['style_id' => $style['id'], 'ship_on' => '2026-12-15', 'unit_price_minor' => 425, 'lines' => [
        ['size' => 'M', 'color' => 'Navy', 'quantity' => 600], ['size' => 'XL', 'color' => 'Red', 'quantity' => 240],
    ]])->assertCreated()->json('data');
    $this->confirm = fn () => ($this->as)()->postJson(($this->fac)("orders/{$this->order['id']}/confirm"), ['base_version' => $this->order['version']])->assertOk()->json('data');
    $this->give = fn (array $lines, array $extra = [], ?string $token = null) => ($this->as)($token ?? $this->keeper)->postJson(($this->fac)("orders/{$this->order['id']}/issues"), [
        'kind' => 'issue', 'warehouse_id' => $this->store['id'], 'lines' => $lines, ...$extra,
    ]);
    $this->cut = fn (array $lines, array $extra = [], ?string $token = null) => ($this->as)($token ?? $this->cutter)->postJson(($this->fac)("orders/{$this->order['id']}/cuttings"), [
        'lines' => $lines, ...$extra,
    ]);
    $this->wip = function () {
        $lines = DB::table('acc_journal_lines')->where('account_id', accountId($this->w->c1, '1152'));

        return (int) (clone $lines)->sum('debit_minor') - (int) $lines->sum('credit_minor');
    };
});

it('gives materials to a confirmed order: stock out at its cost, work in progress in the books, once per tap', function () {
    // Not before the order is confirmed.
    ($this->give)([['item_id' => $this->fabric['id'], 'quantity_milli' => 100000]])->assertStatus(409)->assertJsonPath('code', 'wrong_status');
    ($this->confirm)();

    $issue = ($this->give)([['item_id' => $this->fabric['id'], 'quantity_milli' => 100000], ['item_id' => $this->button['id'], 'quantity_milli' => 1000000]], ['op_id' => 'tap-1'])
        ->assertCreated()->json('data');
    // 100 kg × 450.00 + 1,000 × 0.80.
    expect($issue)->toMatchArray(['kind' => 'issue', 'cost_minor' => 4500000 + 80000, 'issued_on' => '2026-11-02'])
        ->and($issue['journal_id'])->not->toBeNull()
        ->and(collect($issue['lines'])->pluck('cost_minor', 'item_id')->all())->toBe([$this->fabric['id'] => 4500000, $this->button['id'] => 80000]);
    ($this->give)([['item_id' => $this->fabric['id'], 'quantity_milli' => 100000]], ['op_id' => 'tap-1'])->assertOk()->assertJsonPath('data.id', $issue['id']);
    expect(($this->wip)())->toBe(4580000);
    expect(DB::table('inv_balances')->where('item_id', $this->fabric['id'])->value('quantity_milli'))->toBe(200000);

    $needs = collect(($this->as)()->getJson(($this->fac)("orders/{$this->order['id']}/requirements"))->assertOk()->json('data'))->keyBy('sku');
    expect($needs['FAB-JERSEY'])->toMatchArray(['need_milli' => 176400, 'issued_milli' => 100000, 'to_issue_milli' => 76400, 'on_hand_milli' => 200000, 'short_milli' => 0])
        ->and($needs['BTN-12L'])->toMatchArray(['issued_milli' => 1000000, 'to_issue_milli' => 680000]);
    expect(($this->as)()->getJson(($this->fac)("orders/{$this->order['id']}/issues"))->assertOk()->json('data'))->toHaveCount(1);

    // Only the bill of materials; never a day ahead.
    ($this->give)([['item_id' => $this->thread['id'], 'quantity_milli' => 1000]])->assertUnprocessable()->assertJsonPath('code', 'not_in_bom');
    ($this->give)([['item_id' => $this->fabric['id'], 'quantity_milli' => 1000]], ['issued_on' => '2026-11-03'])->assertUnprocessable()->assertJsonPath('code', 'future_date');
});

it('asks again for more than the order needs, or refuses it where the company blocks it', function () {
    ($this->confirm)();
    // 176.400 kg needed: 180 is 3.6 kg over.
    ($this->give)([['item_id' => $this->fabric['id'], 'quantity_milli' => 180000]])->assertUnprocessable()
        ->assertJsonPath('code', 'over_issue')->assertJsonPath('items.0.need_milli', 176400)->assertJsonPath('items.0.quantity_milli', 180000);
    ($this->give)([['item_id' => $this->fabric['id'], 'quantity_milli' => 180000]], ['allow_over' => true])->assertCreated();

    orgRule($this->w->c1, 'factory_erp.over_issue', 'block');
    ($this->give)([['item_id' => $this->button['id'], 'quantity_milli' => 1700000]], ['allow_over' => true])->assertUnprocessable()->assertJsonPath('code', 'over_issue_blocked');
    ($this->give)([['item_id' => $this->button['id'], 'quantity_milli' => 1680000]])->assertCreated();
});

it('takes materials back: at most what is on the floor, at its share of the cost, everything back leaves nothing in progress', function () {
    ($this->confirm)();
    ($this->give)([['item_id' => $this->fabric['id'], 'quantity_milli' => 100000]])->assertCreated();
    $back = fn (int $milli) => ($this->give)([['item_id' => $this->fabric['id'], 'quantity_milli' => $milli]], ['kind' => 'return']);

    $back(100001)->assertUnprocessable()->assertJsonPath('code', 'too_much_back');
    ($this->give)([['item_id' => $this->button['id'], 'quantity_milli' => 1]], ['kind' => 'return'])->assertUnprocessable()->assertJsonPath('code', 'too_much_back');
    // A third, then the rest: the cost comes back to the last minor unit.
    expect($back(33333)->assertCreated()->json('data.cost_minor'))->toBe(intdiv(4500000 * 33333, 100000));
    $back(66667)->assertCreated();
    expect(($this->wip)())->toBe(0)
        ->and(DB::table('inv_balances')->where('item_id', $this->fabric['id'])->first())->quantity_milli->toBe(300000)->value_minor->toBe(13500000);
});

it('cuts lays by size and colour up to the allowance; the first lay starts production; a wrong lay is voided', function () {
    // Not before the order is confirmed.
    ($this->cut)([['size' => 'M', 'color' => 'Navy', 'quantity' => 100]])->assertStatus(409);
    ($this->confirm)();

    $first = ($this->cut)([['size' => 'M', 'color' => 'Navy', 'quantity' => 300], ['size' => 'XL', 'color' => 'Red', 'quantity' => 0]], ['op_id' => 'lay-1'])->assertCreated()->json('data');
    expect($first)->toMatchArray(['lay_number' => 1, 'pieces' => 300])->and($first['lines'])->toHaveCount(1);
    ($this->cut)([['size' => 'M', 'color' => 'Navy', 'quantity' => 300]], ['op_id' => 'lay-1'])->assertOk()->assertJsonPath('data.id', $first['id']);
    ($this->as)()->getJson(($this->fac)("orders/{$this->order['id']}"))->assertJsonPath('data.status', 'in_production');

    // 600 ordered + 3% = 618: 300 cut, 318 more at most; a cell not ordered gets none.
    ($this->cut)([['size' => 'M', 'color' => 'Navy', 'quantity' => 319]])->assertUnprocessable()->assertJsonPath('code', 'over_cut');
    ($this->cut)([['size' => 'L', 'color' => 'Navy', 'quantity' => 1]])->assertUnprocessable()->assertJsonPath('code', 'over_cut');
    ($this->cut)([['size' => 'M', 'color' => 'Navy', 'quantity' => 0]])->assertUnprocessable()->assertJsonPath('code', 'no_pieces');
    $second = ($this->cut)([['size' => 'M', 'color' => 'Navy', 'quantity' => 318], ['size' => 'XL', 'color' => 'Red', 'quantity' => 240]])->assertCreated()->json('data');
    expect($second['lay_number'])->toBe(2);

    // Voided with a reason: its pieces stop counting and can be cut again.
    ($this->as)($this->cutter)->postJson(($this->fac)("orders/{$this->order['id']}/cuttings/{$second['id']}/void"), ['base_version' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');
    ($this->as)($this->cutter)->postJson(($this->fac)("orders/{$this->order['id']}/cuttings/{$second['id']}/void"), ['base_version' => 1, 'reason' => 'Wrong marker'])
        ->assertOk()->assertJsonPath('data.void_reason', 'Wrong marker');
    ($this->as)($this->cutter)->postJson(($this->fac)("orders/{$this->order['id']}/cuttings/{$second['id']}/void"), ['base_version' => 2, 'reason' => 'Again'])->assertStatus(409);
    $read = ($this->as)()->getJson(($this->fac)("orders/{$this->order['id']}/cuttings"))->assertOk();
    expect($read->json('meta.cut'))->toBe(['M|Navy' => 300])->and($read->json('data'))->toHaveCount(2);
    expect(($this->cut)([['size' => 'M', 'color' => 'Navy', 'quantity' => 318]])->assertCreated()->json('data.lay_number'))->toBe(3);

    // The company may allow none above the order.
    orgRule($this->w->c1, 'factory_erp.over_cut_bp', 0);
    ($this->cut)([['size' => 'XL', 'color' => 'Red', 'quantity' => 241]])->assertUnprocessable()->assertJsonPath('code', 'over_cut');
});

it('lets only the right people do it, never across companies, and not with the module off', function () {
    ($this->confirm)();
    $merchandiser = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['factory_erp.view', 'factory_erp.merchandise'], 'Merchandiser')), $this->w->c1);
    ($this->give)([['item_id' => $this->fabric['id'], 'quantity_milli' => 1000]], token: $merchandiser)->assertForbidden();
    ($this->give)([['item_id' => $this->fabric['id'], 'quantity_milli' => 1000]], token: $this->cutter)->assertForbidden();
    ($this->cut)([['size' => 'M', 'color' => 'Navy', 'quantity' => 10]], token: $this->keeper)->assertForbidden();
    ($this->as)($merchandiser)->getJson(($this->fac)("orders/{$this->order['id']}/issues"))->assertOk();

    // Unknown fields are refused.
    ($this->give)([['item_id' => $this->fabric['id'], 'quantity_milli' => 1000]], ['cost_minor' => 1])->assertUnprocessable();

    // Another company: the same 404 whatever it holds.
    $other = createMember($this->w->c2);
    $otherToken = orgToken($other, $this->w->c2);
    ($this->as)($otherToken)->getJson(($this->fac)("orders/{$this->order['id']}/issues", $this->w->c2))->assertNotFound();
    ($this->as)($otherToken)->postJson(($this->fac)("orders/{$this->order['id']}/cuttings", $this->w->c2), ['lines' => [['size' => 'M', 'color' => 'Navy', 'quantity' => 1]]])->assertNotFound();
    ($this->as)($otherToken)->getJson(($this->fac)("orders/{$this->order['id']}/cuttings"))->assertNotFound();

    toggles()->disable($this->w->c1, 'factory_erp', 'Test');
    ($this->give)([['item_id' => $this->fabric['id'], 'quantity_milli' => 1000]])->assertForbidden();
    ($this->as)()->getJson(($this->fac)("orders/{$this->order['id']}/cuttings"))->assertForbidden();
});
