<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * FAC-2b: sewing lines, what they make by the hour (good pieces and
 * rejects, never above what was cut), finished goods into the store at
 * their share of the order's work in progress (the last one takes the
 * rest), the dashboard and bell, output written offline, and who may do
 * what: permission, other companies, the module off.
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
    $this->supervisor = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['factory_erp.view', 'factory_erp.produce'], 'Line supervisor')), $this->w->c1);
    $post = fn (string $path, array $data) => ($this->as)($this->keeper)->postJson(($this->inv)($path), $data)->assertCreated()->json('data');
    $kg = $post('units', ['code' => 'KG', 'name' => ['en' => 'Kilogram'], 'decimals' => 3]);
    $pcs = $post('units', ['code' => 'PCS', 'name' => ['en' => 'Pieces'], 'decimals' => 0]);
    $this->store = $post('warehouses', ['code' => 'MAIN', 'name' => ['en' => 'Main store'], 'unit_id' => $this->w->c1->id]);
    $this->fabric = $post('items', ['sku' => 'FAB-JERSEY', 'name' => ['en' => 'Single jersey fabric'], 'unit_id' => $kg['id'], 'kind' => 'stock']);
    $this->polo = $post('items', ['sku' => 'FG-POLO', 'name' => ['en' => 'Basic polo (finished)'], 'unit_id' => $pcs['id'], 'kind' => 'stock']);
    $receipt = ($this->as)($this->keeper)->postJson(($this->inv)('documents'), ['type' => 'receipt', 'warehouse_id' => $this->store['id'], 'document_date' => '2026-11-01',
        'lines' => [['item_id' => $this->fabric['id'], 'quantity_milli' => 300000, 'unit_cost_minor' => 45000]]])->assertCreated()->json('data');
    ($this->as)($this->keeper)->postJson(($this->inv)("documents/{$receipt['id']}/post"), ['base_version' => $receipt['version']])->assertOk();

    $buyer = ($this->as)()->postJson(($this->fac)('buyers'), ['name' => 'Nordic Wear AB'])->assertCreated()->json('data');
    $this->style = ($this->as)()->postJson(($this->fac)('styles'), ['buyer_id' => $buyer['id'], 'code' => 'NW-2611', 'name' => 'Basic polo', 'sizes' => ['M', 'XL'], 'colors' => ['Navy', 'Red']])
        ->assertCreated()->json('data');
    ($this->as)()->putJson(($this->fac)("styles/{$this->style['id']}/bom"), ['base_version' => 1, 'lines' => [['item_id' => $this->fabric['id'], 'consumption_milli' => 2400, 'wastage_bp' => 500]]])->assertOk();
    $this->order = ($this->as)()->postJson(($this->fac)('orders'), ['style_id' => $this->style['id'], 'ship_on' => '2026-12-15', 'unit_price_minor' => 425, 'lines' => [
        ['size' => 'M', 'color' => 'Navy', 'quantity' => 600], ['size' => 'XL', 'color' => 'Red', 'quantity' => 240],
    ]])->assertCreated()->json('data');
    ($this->as)()->postJson(($this->fac)("orders/{$this->order['id']}/confirm"), ['base_version' => 1])->assertOk();
    // 100 kg on the floor: 4,500,000 in work in progress; all 840 pieces cut.
    ($this->as)($this->keeper)->postJson(($this->fac)("orders/{$this->order['id']}/issues"), ['kind' => 'issue', 'warehouse_id' => $this->store['id'],
        'lines' => [['item_id' => $this->fabric['id'], 'quantity_milli' => 100000]]])->assertCreated();
    ($this->as)()->postJson(($this->fac)("orders/{$this->order['id']}/cuttings"), ['lines' => [
        ['size' => 'M', 'color' => 'Navy', 'quantity' => 600], ['size' => 'XL', 'color' => 'Red', 'quantity' => 240],
    ]])->assertCreated();

    $this->line = ($this->as)()->postJson(($this->fac)('lines'), ['code' => 'l-01', 'name' => 'Line 1', 'machines' => 32])->assertCreated()->json('data');
    $this->sew = fn (array $cells, array $extra = [], ?string $token = null) => ($this->as)($token ?? $this->supervisor)->postJson(($this->fac)('outputs'), [
        'line_id' => $this->line['id'], 'order_id' => $this->order['id'], 'hour' => 9, 'lines' => $cells, ...$extra,
    ]);
    $this->receive = fn (array $cells, array $extra = [], ?string $token = null) => ($this->as)($token ?? $this->keeper)->postJson(($this->fac)("orders/{$this->order['id']}/receipts"), [
        'warehouse_id' => $this->store['id'], 'lines' => $cells, ...$extra,
    ]);
    $this->wip = function () {
        $lines = DB::table('acc_journal_lines')->where('account_id', accountId($this->w->c1, '1152'));

        return (int) (clone $lines)->sum('debit_minor') - (int) $lines->sum('credit_minor');
    };
});

it('keeps sewing lines: code unique and in capitals, switched off rather than removed, set up by managers', function () {
    expect($this->line)->toMatchArray(['code' => 'L-01', 'machines' => 32, 'is_active' => true]);
    ($this->as)()->postJson(($this->fac)('lines'), ['code' => 'L-01', 'name' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors('code');
    ($this->as)($this->supervisor)->postJson(($this->fac)('lines'), ['code' => 'L-02', 'name' => 'Line 2'])->assertForbidden();
    ($this->as)()->patchJson(($this->fac)("lines/{$this->line['id']}"), ['is_active' => false, 'base_version' => 1])->assertOk()->assertJsonPath('data.is_active', false);
    // A line not in use takes no output.
    ($this->sew)([['size' => 'M', 'color' => 'Navy', 'good' => 10]])->assertUnprocessable()->assertJsonPath('code', 'line_off');
    expect(($this->as)($this->supervisor)->getJson(($this->fac)('lines?active=1'))->assertOk()->json('data'))->toBe([]);
});

it('writes what a line sews by the hour, never above what was cut, once per tap; a wrong entry is voided', function () {
    $entry = ($this->sew)([['size' => 'M', 'color' => 'Navy', 'good' => 120, 'rejects' => 4], ['size' => 'XL', 'color' => 'Red', 'good' => 0]], ['op_id' => 'tap-1'])
        ->assertCreated()->json('data');
    expect($entry)->toMatchArray(['good' => 120, 'rejects' => 4, 'hour' => 9, 'worked_on' => '2026-11-02'])->and($entry['lines'])->toHaveCount(1);
    ($this->sew)([['size' => 'M', 'color' => 'Navy', 'good' => 120]], ['op_id' => 'tap-1'])->assertOk()->assertJsonPath('data.id', $entry['id']);

    // The hour is needed where the company writes by the hour, and left out where by the day.
    ($this->sew)([['size' => 'M', 'color' => 'Navy', 'good' => 1]], ['hour' => null])->assertUnprocessable()->assertJsonPath('code', 'hour_needed');
    orgRule($this->w->c1, 'factory_erp.output_by', 'day');
    ($this->sew)([['size' => 'M', 'color' => 'Navy', 'good' => 1]])->assertUnprocessable()->assertJsonPath('code', 'hour_not_used');
    ($this->sew)([['size' => 'M', 'color' => 'Navy', 'good' => 50]], ['hour' => null])->assertCreated()->assertJsonPath('data.hour', null);

    // 600 cut, 174 written: 426 left; a size and colour not cut has none.
    ($this->sew)([['size' => 'M', 'color' => 'Navy', 'good' => 420, 'rejects' => 7]], ['hour' => null])->assertUnprocessable()->assertJsonPath('code', 'over_sewn')->assertJsonPath('left', 426);
    ($this->sew)([['size' => 'M', 'color' => 'Red', 'good' => 1]], ['hour' => null])->assertUnprocessable()->assertJsonPath('code', 'over_sewn');
    ($this->sew)([['size' => 'M', 'color' => 'Navy', 'good' => 0]], ['hour' => null])->assertUnprocessable()->assertJsonPath('code', 'no_pieces');
    ($this->sew)([['size' => 'M', 'color' => 'Navy', 'good' => 1]], ['hour' => null, 'worked_on' => '2026-11-03'])->assertUnprocessable()->assertJsonPath('code', 'future_date');

    ($this->as)($this->supervisor)->postJson(($this->fac)("outputs/{$entry['id']}/void"), ['base_version' => 1, 'reason' => 'Counted twice'])->assertOk()->assertJsonPath('data.void_reason', 'Counted twice');
    $progress = ($this->as)()->getJson(($this->fac)("orders/{$this->order['id']}/progress"))->assertOk()->json('data');
    expect($progress['cells']['M|Navy'])->toBe(['ordered' => 600, 'cut' => 600, 'good' => 50, 'rejects' => 0, 'received' => 0])
        ->and($progress['totals']['good'])->toBe(50)->and($progress['wip_minor'])->toBe(4500000);

    $day = ($this->as)($this->supervisor)->getJson(($this->fac)('outputs?date=2026-11-02'))->assertOk();
    expect($day->json('data'))->toHaveCount(2)
        ->and(collect($day->json('meta.orders'))->pluck('number')->all())->toBe([$this->order['number']])
        ->and($day->json('meta.lines.0.code'))->toBe('L-01');
    // A lay with sewn pieces stays.
    $lay = ($this->as)()->getJson(($this->fac)("orders/{$this->order['id']}/cuttings"))->json('data.0');
    ($this->as)()->postJson(($this->fac)("orders/{$this->order['id']}/cuttings/{$lay['id']}/void"), ['base_version' => $lay['version'], 'reason' => 'Wrong'])
        ->assertStatus(409)->assertJsonPath('code', 'cut_in_use');
});

it('takes finished goods into the store at their share of work in progress; the last takes the rest', function () {
    ($this->sew)([['size' => 'M', 'color' => 'Navy', 'good' => 500, 'rejects' => 10], ['size' => 'XL', 'color' => 'Red', 'good' => 240]])->assertCreated();

    // The style needs its finished item first.
    ($this->receive)([['size' => 'M', 'color' => 'Navy', 'quantity' => 100]])->assertUnprocessable()->assertJsonPath('code', 'no_finished_item');
    ($this->as)()->patchJson(($this->fac)("styles/{$this->style['id']}"), ['finished_item_id' => $this->polo['id'], 'base_version' => 2])
        ->assertOk()->assertJsonPath('data.finished_item_id', $this->polo['id']);

    ($this->receive)([['size' => 'M', 'color' => 'Navy', 'quantity' => 501]])->assertUnprocessable()->assertJsonPath('code', 'over_received')->assertJsonPath('left', 500);
    // 840 cut − 10 rejects = 830 to come; 415 of them: half the work in progress.
    $first = ($this->receive)([['size' => 'M', 'color' => 'Navy', 'quantity' => 415]], ['op_id' => 'fg-1'])->assertCreated()->json('data');
    expect($first)->toMatchArray(['pieces' => 415, 'cost_minor' => intdiv(4500000 * 415, 830), 'is_last' => false, 'item_id' => $this->polo['id']])
        ->and($first['journal_id'])->not->toBeNull();
    ($this->receive)([['size' => 'M', 'color' => 'Navy', 'quantity' => 415]], ['op_id' => 'fg-1'])->assertOk()->assertJsonPath('data.id', $first['id']);
    expect(($this->wip)())->toBe(4500000 - $first['cost_minor']);

    // Output in the store stays covered by good pieces.
    $entry = ($this->as)($this->supervisor)->getJson(($this->fac)('outputs'))->json('data.0');
    ($this->as)($this->supervisor)->postJson(($this->fac)("outputs/{$entry['id']}/void"), ['base_version' => 1, 'reason' => 'Wrong'])->assertUnprocessable()->assertJsonPath('code', 'over_received');

    $last = ($this->receive)([['size' => 'M', 'color' => 'Navy', 'quantity' => 85], ['size' => 'XL', 'color' => 'Red', 'quantity' => 100]], ['is_last' => true])->assertCreated()->json('data');
    expect($last['cost_minor'])->toBe(4500000 - $first['cost_minor'])->and($last['is_last'])->toBeTrue();
    expect(($this->wip)())->toBe(0)
        ->and(($this->as)()->getJson(($this->fac)("orders/{$this->order['id']}/progress"))->json('data.wip_minor'))->toBe(0);
    $balance = DB::table('inv_balances')->where('item_id', $this->polo['id'])->first();
    expect((int) $balance->quantity_milli)->toBe(600000)->and((int) $balance->value_minor)->toBe(4500000);
    expect(($this->as)()->getJson(($this->fac)("orders/{$this->order['id']}/receipts"))->assertOk()->json('data'))->toHaveCount(2);
});

it('shows today on the home and rings the bell for a line with many rejects', function () {
    ($this->sew)([['size' => 'M', 'color' => 'Navy', 'good' => 190, 'rejects' => 10]])->assertCreated();
    $widget = fn (string $key) => ($this->as)()->getJson("/api/modules/factory_erp/dashboard/{$key}")->assertOk()->json('data');
    expect($widget('today')['value'])->toBe(190)
        ->and($widget('lines')['items'][0]['value'])->toBe(190)
        ->and($widget('wip'))->toMatchArray(['value' => 4500000, 'format' => 'money']);

    // 10 of 200 is 5%: the default warning.
    $bell = fn () => collect(($this->as)()->getJson('/api/attention')->assertOk()->json('data'))->pluck('key')->all();
    expect($bell())->toContain('factory_erp.rejects_high');
    orgRule($this->w->c1, 'factory_erp.reject_warn_bp', 600);
    expect($bell())->not->toContain('factory_erp.rejects_high');
});

it('takes output written offline, once, checked again when it arrives', function () {
    toggles()->enable($this->w->g1, 'offline_mode', 'Test setup');
    $phone = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['factory_erp.view', 'factory_erp.produce', 'offline_mode.use'], 'Line phone')), $this->w->c1);
    $device = ($this->as)($phone)->postJson('/api/offline/devices', ['name' => 'Line phone', 'platform' => 'Android'])->assertCreated()->json('data');
    $sync = fn (array $operations) => ($this->as)($phone)->postJson('/api/sync', ['device_id' => $device['device']['id'], 'lease' => $device['lease'], 'operations' => $operations]);
    $op = fn (int $good) => [
        'op_id' => (string) Str::ulid(), 'kind' => 'factory_erp.output', 'action' => 'create', 'made_at' => '2026-11-02T03:30:00+00:00', 'lease_id' => $device['lease_id'],
        'data' => ['line_id' => $this->line['id'], 'order_id' => $this->order['id'], 'worked_on' => '2026-11-02', 'hour' => 9, 'lines' => [['size' => 'XL', 'color' => 'Red', 'good' => $good]]],
    ];
    $fine = $op(200);
    $tooMany = $op(41);
    $results = $sync([$fine, $tooMany])->assertOk()->json('results');
    expect($results[$fine['op_id']]['status'])->toBe('applied')
        ->and($results[$tooMany['op_id']])->toMatchArray(['status' => 'rejected', 'code' => 'over_sewn']);
    expect($sync([$fine])->json("results.{$fine['op_id']}.record_id"))->toBe($results[$fine['op_id']]['record_id']);
    expect(($this->as)()->getJson(($this->fac)("orders/{$this->order['id']}/progress"))->json('data.cells')['XL|Red']['good'])->toBe(200);
});

it('keeps sewing and finished goods to the right people, the company, and the module being on', function () {
    ($this->sew)([['size' => 'M', 'color' => 'Navy', 'good' => 10]], token: $this->keeper)->assertForbidden();
    ($this->receive)([['size' => 'M', 'color' => 'Navy', 'quantity' => 1]], token: $this->supervisor)->assertForbidden();
    ($this->sew)([['size' => 'M', 'color' => 'Navy', 'good' => 10]], ['made_by' => 'x'])->assertUnprocessable();

    $otherToken = orgToken(createMember($this->w->c2), $this->w->c2);
    ($this->as)($otherToken)->getJson(($this->fac)("orders/{$this->order['id']}/progress", $this->w->c2))->assertNotFound();
    ($this->as)($otherToken)->patchJson(($this->fac)("lines/{$this->line['id']}", $this->w->c2), ['name' => 'Mine', 'base_version' => 1])->assertNotFound();
    ($this->as)($otherToken)->postJson(($this->fac)('outputs', $this->w->c2), ['line_id' => $this->line['id'], 'order_id' => $this->order['id'], 'hour' => 9, 'lines' => [['size' => 'M', 'color' => 'Navy', 'good' => 1]]])
        ->assertUnprocessable()->assertJsonPath('code', 'line_off');
    expect(($this->as)($otherToken)->getJson(($this->fac)('outputs', $this->w->c2))->assertOk()->json('meta.lines'))->toBe([]);

    toggles()->disable($this->w->c1, 'factory_erp', 'Test');
    ($this->sew)([['size' => 'M', 'color' => 'Navy', 'good' => 10]])->assertForbidden();
    ($this->as)()->getJson(($this->fac)('lines'))->assertForbidden();
});
