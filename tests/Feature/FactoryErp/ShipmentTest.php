<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\FactoryErp\Services\Shipments;

/*
 * FAC-4: shipments of finished goods with a packing list (never more than
 * the store holds and is not shipped yet), shipping (final inspection,
 * stock out at cost, cost of sales, a draft invoice to the buyer in the
 * company's currency at the shipment's rate), the order shipped with its
 * last shipment; who may do what, other companies, the module off.
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
    $inv = fn (string $path) => "/api/organizations/{$this->w->c1->id}/inventory/{$path}";

    setUpBooks($this, $this->token, $this->w->c1, ['template' => 'factory'])->assertCreated();
    $this->keeper = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['inventory.view', 'inventory.manage', 'factory_erp.view', 'factory_erp.issue'], 'Store keeper')), $this->w->c1);
    $post = fn (string $path, array $data) => ($this->as)($this->keeper)->postJson($inv($path), $data)->assertCreated()->json('data');
    $kg = $post('units', ['code' => 'KG', 'name' => ['en' => 'Kilogram'], 'decimals' => 3]);
    $pcs = $post('units', ['code' => 'PCS', 'name' => ['en' => 'Pieces'], 'decimals' => 0]);
    $this->store = $post('warehouses', ['code' => 'MAIN', 'name' => ['en' => 'Main store'], 'unit_id' => $this->w->c1->id]);
    $fabric = $post('items', ['sku' => 'FAB', 'name' => ['en' => 'Fabric'], 'unit_id' => $kg['id'], 'kind' => 'stock']);
    $this->polo = $post('items', ['sku' => 'FG-POLO', 'name' => ['en' => 'Polo (finished)'], 'unit_id' => $pcs['id'], 'kind' => 'stock']);
    $receipt = ($this->as)($this->keeper)->postJson($inv('documents'), ['type' => 'receipt', 'warehouse_id' => $this->store['id'], 'document_date' => '2026-11-01',
        'lines' => [['item_id' => $fabric['id'], 'quantity_milli' => 200000, 'unit_cost_minor' => 45000]]])->assertCreated()->json('data');
    ($this->as)($this->keeper)->postJson($inv("documents/{$receipt['id']}/post"), ['base_version' => $receipt['version']])->assertOk();

    $this->buyer = ($this->as)()->postJson(($this->fac)('buyers'), ['name' => 'Nordic Wear AB', 'email' => 'orders@nordic.example'])->assertCreated()->json('data');
    $style = ($this->as)()->postJson(($this->fac)('styles'), ['buyer_id' => $this->buyer['id'], 'code' => 'NW-2611', 'name' => 'Basic polo', 'sizes' => ['M', 'L'], 'colors' => ['Navy'],
        'finished_item_id' => $this->polo['id']])->assertCreated()->json('data');
    ($this->as)()->putJson(($this->fac)("styles/{$style['id']}/bom"), ['base_version' => 1, 'lines' => [['item_id' => $fabric['id'], 'consumption_milli' => 2400, 'wastage_bp' => 500]]])->assertOk();
    // 600 pieces at 4.25 USD.
    $this->order = ($this->as)()->postJson(($this->fac)('orders'), ['style_id' => $style['id'], 'po_number' => 'PO-4471', 'ship_on' => '2026-12-15', 'unit_price_minor' => 425,
        'lines' => [['size' => 'M', 'color' => 'Navy', 'quantity' => 400], ['size' => 'L', 'color' => 'Navy', 'quantity' => 200]]])->assertCreated()->json('data');
    ($this->as)()->postJson(($this->fac)("orders/{$this->order['id']}/confirm"), ['base_version' => 1])->assertOk();
    // 100 kg on the floor (4,500,000), all cut, sewn and in the store: 7,500 a piece.
    ($this->as)($this->keeper)->postJson(($this->fac)("orders/{$this->order['id']}/issues"), ['kind' => 'issue', 'warehouse_id' => $this->store['id'], 'allow_over' => true,
        'lines' => [['item_id' => $fabric['id'], 'quantity_milli' => 100000]]])->assertCreated();
    $cells = [['size' => 'M', 'color' => 'Navy', 'quantity' => 400], ['size' => 'L', 'color' => 'Navy', 'quantity' => 200]];
    ($this->as)()->postJson(($this->fac)("orders/{$this->order['id']}/cuttings"), ['lines' => $cells])->assertCreated();
    $line = ($this->as)()->postJson(($this->fac)('lines'), ['code' => 'L-01', 'name' => 'Line 1'])->assertCreated()->json('data');
    ($this->as)()->postJson(($this->fac)('outputs'), ['line_id' => $line['id'], 'order_id' => $this->order['id'], 'hour' => 9,
        'lines' => array_map(fn (array $cell) => ['size' => $cell['size'], 'color' => $cell['color'], 'good' => $cell['quantity']], $cells)])->assertCreated();
    ($this->as)($this->keeper)->postJson(($this->fac)("orders/{$this->order['id']}/receipts"), ['warehouse_id' => $this->store['id'], 'is_last' => true, 'lines' => $cells])->assertCreated();

    $this->shipment = fn (array $data = [], ?string $token = null) => ($this->as)($token)->postJson(($this->fac)('shipments'), [
        'order_id' => $this->order['id'], 'ship_on' => '2026-11-02', 'mode' => 'sea', 'carrier' => 'Maersk Line', 'transport_ref' => 'BL-778812', 'destination' => 'Gothenburg, Sweden',
        'warehouse_id' => $this->store['id'], 'exchange_rate' => '110.5',
        'cartons' => [['from_no' => 1, 'to_no' => 20, 'size' => 'M', 'color' => 'Navy', 'pieces_each' => 12, 'net_grams_each' => 4800, 'gross_grams_each' => 5400, 'length_mm' => 600, 'width_mm' => 400, 'height_mm' => 300]],
        ...$data,
    ]);
    $this->passFinal = function () {
        $qc = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['factory_erp.view', 'factory_erp.qc'], 'QC')), $this->w->c1);
        ($this->as)($qc)->postJson(($this->fac)('inspections'), ['kind' => 'final', 'order_id' => $this->order['id'], 'lot_size' => 600])->assertCreated()->assertJsonPath('data.result', 'pass');
    };
});

it('makes a numbered draft shipment with a packing list, never more than the store holds', function () {
    $made = ($this->shipment)(['op_id' => 'sh-1'])->assertCreated()->json('data');
    // 20 cartons × 12 = 240 pieces × 4.25 USD.
    expect($made)->toMatchArray(['number' => 'SH-2026-00001', 'status' => 'draft', 'pieces' => 240, 'value_minor' => 102000, 'currency' => 'USD', 'rate_micro' => 110500000, 'exchange_rate' => '110.5'])
        ->and($made['cartons'][0])->toMatchArray(['cartons' => 20, 'pieces' => 240]);
    ($this->shipment)(['op_id' => 'sh-1'])->assertOk()->assertJsonPath('data.id', $made['id']);

    ($this->shipment)(['cartons' => [['from_no' => 1, 'to_no' => 34, 'size' => 'M', 'color' => 'Navy', 'pieces_each' => 12]]])->assertUnprocessable()->assertJsonPath('code', 'over_shipped')->assertJsonPath('left', 400);
    ($this->shipment)(['cartons' => [['from_no' => 1, 'to_no' => 5, 'size' => 'M', 'color' => 'Navy', 'pieces_each' => 12], ['from_no' => 5, 'to_no' => 8, 'size' => 'L', 'color' => 'Navy', 'pieces_each' => 12]]])
        ->assertUnprocessable()->assertJsonPath('code', 'carton_run');
    ($this->shipment)(['exchange_rate' => null])->assertUnprocessable()->assertJsonPath('code', 'rate_needed');
    ($this->shipment)(['cartons' => [['from_no' => 1, 'to_no' => 1, 'size' => 'XL', 'color' => 'Navy', 'pieces_each' => 1]]])->assertUnprocessable()->assertJsonPath('code', 'not_in_style');

    $changed = ($this->as)()->patchJson(($this->fac)("shipments/{$made['id']}"), ['destination' => 'Stockholm', 'base_version' => 1])->assertOk()->json('data');
    expect($changed['destination'])->toBe('Stockholm');
    ($this->as)()->patchJson(($this->fac)("shipments/{$made['id']}"), ['destination' => 'X', 'base_version' => 1])->assertStatus(409);
    expect(($this->as)()->getJson(($this->fac)("orders/{$this->order['id']}/shipments"))->assertOk()->json('meta.ready'))->toEqual(['M|Navy' => 400, 'L|Navy' => 200]);
    expect(Shipments::convert(425, 'USD', 'BDT', 110500000))->toBe(46963)->and(Shipments::rateMicro('0.000001'))->toBe(1);
});

it('ships after a passed final: stock out at cost, cost of sales, a draft invoice; the last shipment ships the order', function () {
    $made = ($this->shipment)()->assertCreated()->json('data');
    ($this->as)($this->keeper)->postJson(($this->fac)("shipments/{$made['id']}/ship"), ['base_version' => 1])->assertStatus(409)->assertJsonPath('code', 'final_not_passed');
    ($this->passFinal)();
    // Shipping is the store keeper's; a merchandiser makes the papers.
    $merchandiser = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['factory_erp.view', 'factory_erp.merchandise'], 'Merchandiser')), $this->w->c1);
    ($this->as)($merchandiser)->postJson(($this->fac)("shipments/{$made['id']}/ship"), ['base_version' => 1])->assertForbidden();

    $shipped = ($this->as)($this->keeper)->postJson(($this->fac)("shipments/{$made['id']}/ship"), ['base_version' => 1])->assertOk()->json('data');
    // 240 pieces at 7,500 each.
    expect($shipped)->toMatchArray(['status' => 'shipped', 'cost_minor' => 1800000])->and($shipped['invoice_id'])->not->toBeNull()->and($shipped['journal_id'])->not->toBeNull();
    expect((int) DB::table('inv_balances')->where('item_id', $this->polo['id'])->value('quantity_milli'))->toBe(360000);
    $invoice = DB::table('acc_documents')->where('id', $shipped['invoice_id'])->first();
    // 240 × (4.25 USD × 110.5 = 469.63) as a draft for an accountant.
    expect($invoice->status)->toBe('draft')->and((int) $invoice->total_minor)->toBe(240 * 46963)->and($invoice->currency_code)->toBe('BDT');
    expect(DB::table('acc_document_lines')->where('document_id', $invoice->id)->value('description'))->toContain('M / Navy');

    // Shipped: it does not change; the order is still in production.
    ($this->as)()->patchJson(($this->fac)("shipments/{$made['id']}"), ['destination' => 'X', 'base_version' => 2])->assertStatus(409)->assertJsonPath('code', 'wrong_status');
    ($this->as)()->getJson(($this->fac)("orders/{$this->order['id']}"))->assertJsonPath('data.status', 'in_production');

    $rest = ($this->shipment)(['is_last' => true, 'cartons' => [
        ['from_no' => 21, 'to_no' => 33, 'size' => 'M', 'color' => 'Navy', 'pieces_each' => 12], ['from_no' => 34, 'to_no' => 34, 'size' => 'M', 'color' => 'Navy', 'pieces_each' => 4],
        ['from_no' => 35, 'to_no' => 44, 'size' => 'L', 'color' => 'Navy', 'pieces_each' => 20],
    ]])->assertCreated()->json('data');
    expect($rest['number'])->toBe('SH-2026-00002')->and($rest['pieces'])->toBe(360);
    ($this->as)($this->keeper)->postJson(($this->fac)("shipments/{$rest['id']}/ship"), ['base_version' => 1])->assertOk();
    ($this->as)()->getJson(($this->fac)("orders/{$this->order['id']}"))->assertJsonPath('data.status', 'shipped');
    expect((int) DB::table('inv_balances')->where('item_id', $this->polo['id'])->value('quantity_milli'))->toBe(0);
});

it('keeps shipments to the right people, the company, and the module being on', function () {
    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['factory_erp.view'], 'Viewer')), $this->w->c1);
    ($this->shipment)(token: $viewer)->assertForbidden();
    ($this->shipment)(['status' => 'shipped'])->assertUnprocessable();
    $made = ($this->shipment)()->assertCreated()->json('data');
    ($this->as)($viewer)->getJson(($this->fac)("shipments/{$made['id']}"))->assertOk()->assertJsonPath('meta.buyer.name', 'Nordic Wear AB');

    $otherToken = orgToken(createMember($this->w->c2), $this->w->c2);
    ($this->as)($otherToken)->getJson(($this->fac)("shipments/{$made['id']}", $this->w->c2))->assertNotFound();
    ($this->as)($otherToken)->postJson(($this->fac)('shipments', $this->w->c2), ['order_id' => $this->order['id'], 'ship_on' => '2026-11-02', 'mode' => 'air', 'warehouse_id' => $this->store['id']])->assertNotFound();

    toggles()->disable($this->w->c1, 'factory_erp', 'Test');
    ($this->as)()->getJson(($this->fac)("shipments/{$made['id']}"))->assertForbidden();
});
