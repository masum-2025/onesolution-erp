<?php

use App\Platform\Audit\AuditLog;
use Carbon\CarbonImmutable;

/*
 * FAC-5: buyers' letters of credit (orders under them, what they have paid
 * for), shipments under them (the order's by default), discrepancies
 * refused or accepted, the country's export permit (EXP in Bangladesh),
 * the bell for L/Cs about to expire; who may do what, other companies.
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

    ($this->passFinal)();
    $this->lc = fn (array $data = [], ?string $token = null) => ($this->as)($token)->postJson(($this->fac)('lcs'), [
        'buyer_id' => $this->buyer['id'], 'number' => 'LC-0042/26', 'issued_on' => '2026-10-20', 'issuing_bank' => 'SEB, Stockholm', 'advising_bank' => 'Dutch-Bangla Bank, Dhaka',
        'currency' => 'USD', 'amount_minor' => 200000, 'tolerance_bp' => 500, 'latest_ship_on' => '2026-11-30', 'expires_on' => '2026-12-15',
        'partial_allowed' => true, 'payment_days' => 60, 'port_loading' => 'Chattogram', 'port_discharge' => 'Gothenburg',
        'documents' => ['commercial_invoice', 'packing_list', 'bill_of_lading', 'beneficiary_certificate'], 'order_ids' => [$this->order['id']], ...$data,
    ]);
});

it('keeps a buyer\'s letter of credit with its orders and what it has paid for', function () {
    $lc = ($this->lc)()->assertCreated()->json('data');
    // 2,000.00 USD ± 5%: at most 2,100.00.
    expect($lc)->toMatchArray(['number' => 'LC-0042/26', 'ceiling_minor' => 210000, 'status' => 'open', 'order_ids' => [$this->order['id']], 'usage' => ['shipped' => 0, 'drafts' => 0, 'ceiling' => 210000, 'left' => 210000]]);
    ($this->lc)()->assertUnprocessable()->assertJsonValidationErrors('number');
    ($this->lc)(['number' => 'LC-2', 'currency' => 'EUR'])->assertUnprocessable()->assertJsonPath('code', 'lc_not_for_order');
    ($this->lc)(['number' => 'LC-3', 'latest_ship_on' => '2026-12-20'])->assertUnprocessable()->assertJsonValidationErrors('latest_ship_on');
    ($this->lc)(['number' => 'LC-4', 'documents' => ['a_poem']])->assertUnprocessable();
    // An order goes under one L/C only.
    ($this->lc)(['number' => 'LC-5'])->assertUnprocessable()->assertJsonPath('code', 'lc_not_for_order');

    $changed = ($this->as)()->patchJson(($this->fac)("lcs/{$lc['id']}"), ['amount_minor' => 300000, 'base_version' => 1])->assertOk()->json('data');
    expect($changed['ceiling_minor'])->toBe(315000);
    ($this->as)()->patchJson(($this->fac)("lcs/{$lc['id']}"), ['amount_minor' => 1, 'base_version' => 1])->assertStatus(409);
    expect(AuditLog::query()->where('action', 'factory_erp.lc_updated')->value('new_values')['amount_minor'] ?? null)->toBe(300000);
    expect(($this->as)()->getJson(($this->fac)('lcs'))->assertOk()->json('data.0.number'))->toBe('LC-0042/26');
});

it('ships under the order\'s L/C, asking again for discrepancies unless the company refuses them', function () {
    $lc = ($this->lc)(['latest_ship_on' => '2026-11-01'])->assertCreated()->json('data');
    $made = ($this->shipment)()->assertCreated()->json('data');
    expect($made['lc_id'])->toBe($lc['id']);
    // Shipped on the 2nd, the L/C's last day was the 1st.
    ($this->as)($this->keeper)->postJson(($this->fac)("shipments/{$made['id']}/ship"), ['base_version' => 1])
        ->assertUnprocessable()->assertJsonPath('code', 'lc_discrepancy')->assertJsonPath('discrepancies', ['lc_late']);
    orgRule($this->w->c1, 'factory_erp.lc_strict', true);
    ($this->as)($this->keeper)->postJson(($this->fac)("shipments/{$made['id']}/ship"), ['base_version' => 1, 'accept_discrepancy' => true])
        ->assertUnprocessable()->assertJsonPath('code', 'lc_discrepancy_blocked');
    orgRule($this->w->c1, 'factory_erp.lc_strict', false);
    $shipped = ($this->as)($this->keeper)->postJson(($this->fac)("shipments/{$made['id']}/ship"), ['base_version' => 1, 'accept_discrepancy' => true])->assertOk()->json('data');
    expect($shipped['discrepancies'])->toBe(['lc_late']);
    expect(($this->as)()->getJson(($this->fac)("lcs/{$lc['id']}"))->json('data.usage'))->toMatchArray(['shipped' => 102000, 'left' => 210000 - 102000]);

    // Above the amount with the tolerance, and parts not allowed.
    ($this->as)()->patchJson(($this->fac)("lcs/{$lc['id']}"), ['latest_ship_on' => '2026-11-30', 'amount_minor' => 150000, 'tolerance_bp' => 0, 'partial_allowed' => false, 'base_version' => 1])->assertOk();
    $more = ($this->shipment)(['cartons' => [['from_no' => 21, 'to_no' => 30, 'size' => 'M', 'color' => 'Navy', 'pieces_each' => 12]]])->assertCreated()->json('data');
    $codes = ($this->as)($this->keeper)->postJson(($this->fac)("shipments/{$more['id']}/ship"), ['base_version' => 1])->assertUnprocessable()->json('discrepancies');
    expect($codes)->toBe(['lc_over', 'lc_partial']);
    // An L/C that is not the order's is refused.
    $other = ($this->lc)(['number' => 'LC-OTHER', 'order_ids' => []])->assertCreated()->json('data');
    ($this->as)()->patchJson(($this->fac)("shipments/{$more['id']}"), ['lc_id' => $other['id'], 'base_version' => 1])->assertUnprocessable()->assertJsonPath('code', 'lc_not_for_order');
});

it('needs the country\'s export permit where it asks for one', function () {
    orgRule($this->w->c1, 'factory_erp.export_permit_required', true);
    orgRule($this->w->c1, 'factory_erp.export_permit_label', 'EXP');
    $made = ($this->shipment)()->assertCreated()->json('data');
    ($this->as)($this->keeper)->postJson(($this->fac)("shipments/{$made['id']}/ship"), ['base_version' => 1])
        ->assertUnprocessable()->assertJsonPath('code', 'export_permit_needed')->assertJsonFragment(['field' => 'export_permit_no']);
    $withPermit = ($this->as)()->patchJson(($this->fac)("shipments/{$made['id']}"), ['export_permit_no' => 'EXP-1234-0042', 'export_permit_on' => '2026-11-01', 'base_version' => 1])->assertOk()->json('data');
    expect($withPermit)->toMatchArray(['export_permit_no' => 'EXP-1234-0042', 'export_permit_on' => '2026-11-01']);
    expect(($this->as)()->getJson(($this->fac)("shipments/{$made['id']}"))->json('meta.export_permit'))->toBe(['required' => true, 'label' => 'EXP']);
    ($this->as)($this->keeper)->postJson(($this->fac)("shipments/{$made['id']}/ship"), ['base_version' => 2])->assertOk();
});

it('rings the bell for L/Cs about to expire, and keeps them to the company', function () {
    ($this->lc)(['expires_on' => '2026-11-10', 'latest_ship_on' => '2026-11-05'])->assertCreated();
    expect(collect(($this->as)()->getJson('/api/attention')->assertOk()->json('data'))->pluck('key')->all())->toContain('factory_erp.lc_expiring');

    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['factory_erp.view'], 'Viewer')), $this->w->c1);
    ($this->lc)(['number' => 'LC-9', 'order_ids' => []], $viewer)->assertForbidden();
    $lc = ($this->as)()->getJson(($this->fac)('lcs'))->json('data.0');
    $otherToken = orgToken(createMember($this->w->c2), $this->w->c2);
    ($this->as)($otherToken)->getJson(($this->fac)("lcs/{$lc['id']}", $this->w->c2))->assertNotFound();
    ($this->as)($otherToken)->patchJson(($this->fac)("lcs/{$lc['id']}", $this->w->c2), ['note' => 'x', 'base_version' => 1])->assertNotFound();
    toggles()->disable($this->w->c1, 'factory_erp', 'Test');
    ($this->as)()->getJson(($this->fac)('lcs'))->assertForbidden();
});
