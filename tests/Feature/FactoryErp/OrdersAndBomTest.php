<?php

use App\Platform\Tenancy\Enums\OrganizationType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\FactoryErp\Services\Requirements;

/*
 * FAC-1: a garments factory's buyers (CRM organizations tagged "buyer"),
 * styles with sizes, colours and a bill of materials, buyers' orders by
 * size and colour, what an order needs from the store against what is
 * there; own fields on styles and orders; status moves; permission,
 * campus, company and module (and its dependencies) off.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    foreach ([$this->w->c1, $this->w->c2] as $company) {
        $company->forceFill(['sector_key' => 'factory'])->save();
    }
    foreach ([$this->w->g1] as $group) {
        foreach (['inventory', 'accounting', 'crm', 'factory_erp'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);
    $this->fac = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/factory/{$path}";
    $this->inv = fn (string $path) => "/api/organizations/{$this->w->c1->id}/inventory/{$path}";

    // The store: fabric by the kilogram, thread and buttons by the piece, one warehouse.
    setUpBooks($this, $this->token, $this->w->c1)->assertCreated();
    $this->keeper = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['inventory.view', 'inventory.manage'], 'Store keeper')), $this->w->c1);
    $post = fn (string $path, array $data) => ($this->as)($this->keeper)->postJson(($this->inv)($path), $data)->assertCreated()->json('data');
    $kg = $post('units', ['code' => 'KG', 'name' => ['en' => 'Kilogram'], 'decimals' => 3]);
    $pcs = $post('units', ['code' => 'PCS', 'name' => ['en' => 'Pieces'], 'decimals' => 0]);
    $this->store = $post('warehouses', ['code' => 'MAIN', 'name' => ['en' => 'Main store'], 'unit_id' => $this->w->c1->id]);
    $this->fabric = $post('items', ['sku' => 'FAB-JERSEY', 'name' => ['en' => 'Single jersey fabric'], 'unit_id' => $kg['id'], 'kind' => 'stock']);
    $this->button = $post('items', ['sku' => 'BTN-12L', 'name' => ['en' => 'Button 12L'], 'unit_id' => $pcs['id'], 'kind' => 'stock']);
    $this->label = $post('items', ['sku' => 'LBL-XL', 'name' => ['en' => 'Size label XL'], 'unit_id' => $pcs['id'], 'kind' => 'stock']);

    $this->buyer = ($this->as)()->postJson(($this->fac)('buyers'), ['name' => 'Nordic Wear AB', 'email' => 'orders@nordic.example', 'address' => 'Stockholm, Sweden'])->assertCreated()->json('data');
    $this->style = ($this->as)()->postJson(($this->fac)('styles'), [
        'buyer_id' => $this->buyer['id'], 'code' => 'NW-2611', 'name' => 'Basic polo', 'product_type' => 'Polo shirt',
        'sizes' => ['S', 'M', 'L', 'XL'], 'colors' => ['Navy', 'Red'],
    ])->assertCreated()->json('data');
    $this->order = fn (array $cells = [], array $extra = []) => ($this->as)()->postJson(($this->fac)('orders'), [
        'style_id' => $this->style['id'], 'po_number' => 'PO-4471', 'ship_on' => '2026-12-15', 'unit_price_minor' => 425,
        'lines' => $cells ?: [['size' => 'M', 'color' => 'Navy', 'quantity' => 600], ['size' => 'XL', 'color' => 'Red', 'quantity' => 240]], ...$extra,
    ]);
});

it('keeps buyers in CRM: an organization tagged buyer, found again from the factory', function () {
    $contact = DB::table('crm_contacts')->where('id', $this->buyer['id'])->first();
    expect($contact->kind)->toBe('organization')->and(json_decode($contact->tags, true))->toBe(['buyer']);
    expect(collect(($this->as)()->getJson(($this->fac)('buyers?q=nordic'))->assertOk()->json('data'))->pluck('name')->all())->toBe(['Nordic Wear AB']);

    // A person in CRM, or an organization without the tag, is not a buyer.
    $person = ($this->as)()->postJson("/api/organizations/{$this->w->c1->id}/crm/contacts", ['name' => 'Karim', 'phone' => '01711-000001'])->assertCreated()->json('data');
    ($this->as)()->postJson(($this->fac)('styles'), ['buyer_id' => $person['id'], 'code' => 'X-1', 'name' => 'Tee', 'sizes' => ['M'], 'colors' => ['White']])
        ->assertUnprocessable()->assertJsonPath('code', 'not_a_buyer');
});

it('makes a style with sizes and colours, keeps its number unique, and keeps a size an order uses', function () {
    expect($this->style)->toMatchArray(['code' => 'NW-2611', 'sizes' => ['S', 'M', 'L', 'XL'], 'colors' => ['Navy', 'Red'], 'consumption_basis' => 'dozen']);
    ($this->as)()->postJson(($this->fac)('styles'), ['buyer_id' => $this->buyer['id'], 'code' => 'NW-2611', 'name' => 'Again', 'sizes' => ['M'], 'colors' => ['Navy']])
        ->assertUnprocessable()->assertJsonValidationErrors('code');

    ($this->order)()->assertCreated();
    // XL has pieces in the order: it stays; S can go.
    ($this->as)()->patchJson(($this->fac)("styles/{$this->style['id']}"), ['sizes' => ['M', 'L'], 'base_version' => 1])->assertStatus(409)->assertJsonPath('code', 'size_in_use');
    ($this->as)()->patchJson(($this->fac)("styles/{$this->style['id']}"), ['sizes' => ['M', 'L', 'XL'], 'base_version' => 1])->assertOk()->assertJsonPath('data.sizes', ['M', 'L', 'XL']);
});

it('numbers orders, keeps pieces by size and colour, refuses a size the style lacks, and moves them on', function () {
    $made = ($this->order)(extra: ['op_id' => 'tap-1'])->assertCreated()->json('data');
    expect($made)->toMatchArray(['number' => 'FO-2026-00001', 'status' => 'draft', 'currency' => 'USD', 'pieces' => 840, 'value_minor' => 840 * 425, 'buyer_id' => $this->buyer['id']]);
    ($this->order)(extra: ['op_id' => 'tap-1'])->assertOk()->assertJsonPath('data.id', $made['id']);
    expect(($this->order)()->assertCreated()->json('data.number'))->toBe('FO-2026-00002');

    ($this->order)([['size' => 'XXL', 'color' => 'Navy', 'quantity' => 10]])->assertUnprocessable()->assertJsonPath('code', 'not_in_style');

    // A buyer's amendment while confirmed; zeros leave the cell out.
    $confirmed = ($this->as)()->postJson(($this->fac)("orders/{$made['id']}/confirm"), ['base_version' => 1])->assertOk()->json('data');
    $amended = ($this->as)()->patchJson(($this->fac)("orders/{$made['id']}"), ['lines' => [['size' => 'M', 'color' => 'Navy', 'quantity' => 720], ['size' => 'XL', 'color' => 'Red', 'quantity' => 0]], 'base_version' => $confirmed['version']])
        ->assertOk()->json('data');
    expect($amended['pieces'])->toBe(720)->and($amended['lines'])->toHaveCount(1);

    $started = ($this->as)()->postJson(($this->fac)("orders/{$made['id']}/start"), ['base_version' => $amended['version']])->assertOk()->json('data');
    // In production: no more changes, no cancelling.
    ($this->as)()->patchJson(($this->fac)("orders/{$made['id']}"), ['po_number' => 'X', 'base_version' => $started['version']])->assertStatus(409)->assertJsonPath('code', 'wrong_status');
    ($this->as)()->postJson(($this->fac)("orders/{$made['id']}/cancel"), ['base_version' => $started['version'], 'reason' => 'Buyer dropped it'])->assertStatus(409);
    // Without the final inspection rule (FAC-3b covers it), it ships.
    orgRule($this->w->c1, 'factory_erp.ship_needs_final_pass', false);
    ($this->as)()->postJson(($this->fac)("orders/{$made['id']}/ship"), ['base_version' => $started['version']])->assertOk()->assertJsonPath('data.status', 'shipped');

    // An empty order is not confirmed; cancelling needs a reason.
    $empty = ($this->as)()->postJson(($this->fac)('orders'), ['style_id' => $this->style['id'], 'ship_on' => '2027-01-10'])->assertCreated()->json('data');
    ($this->as)()->postJson(($this->fac)("orders/{$empty['id']}/confirm"), ['base_version' => 1])->assertUnprocessable()->assertJsonPath('code', 'no_pieces');
    ($this->as)()->postJson(($this->fac)("orders/{$empty['id']}/cancel"), ['base_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('reason');
    ($this->as)()->postJson(($this->fac)("orders/{$empty['id']}/cancel"), ['base_version' => 1, 'reason' => 'Sample only'])->assertOk()->assertJsonPath('data.cancelled_reason', 'Sample only');
});

it('works out what an order needs: per dozen or piece, wastage, size or colour lines, rounded up, against the store', function () {
    // Fabric 2.400 kg a dozen +5%; two buttons a piece, as a dozen: 24; XL labels only on XL pieces.
    ($this->as)()->putJson(($this->fac)("styles/{$this->style['id']}/bom"), ['base_version' => 1, 'lines' => [
        ['item_id' => $this->fabric['id'], 'consumption_milli' => 2400, 'wastage_bp' => 500],
        ['item_id' => $this->button['id'], 'consumption_milli' => 24000, 'wastage_bp' => 0],
        ['item_id' => $this->label['id'], 'consumption_milli' => 12000, 'wastage_bp' => 200, 'size' => 'XL'],
    ]])->assertOk()->assertJsonCount(3, 'data.bom');
    ($this->as)()->putJson(($this->fac)("styles/{$this->style['id']}/bom"), ['base_version' => 2, 'lines' => [['item_id' => $this->fabric['id'], 'consumption_milli' => 1, 'size' => 'XXL']]])
        ->assertUnprocessable()->assertJsonPath('code', 'not_in_style');

    // 120 kg of fabric in the store.
    $receipt = ($this->as)($this->keeper)->postJson(($this->inv)('documents'), ['type' => 'receipt', 'warehouse_id' => $this->store['id'], 'document_date' => '2026-11-01', 'lines' => [['item_id' => $this->fabric['id'], 'quantity_milli' => 120000, 'unit_cost_minor' => 45000]]])->assertCreated()->json('data');
    ($this->as)($this->keeper)->postJson(($this->inv)("documents/{$receipt['id']}/post"), ['base_version' => $receipt['version']])->assertOk();

    $order = ($this->order)()->assertCreated()->json('data');
    $needs = collect(($this->as)()->getJson(($this->fac)("orders/{$order['id']}/requirements"))->assertOk()->json('data'))->keyBy('sku');
    // Fabric: 840 pcs × 2.400 kg / 12 × 1.05 = 176.400 kg; 120 held, 56.400 short.
    expect($needs['FAB-JERSEY'])->toMatchArray(['need_milli' => 176400, 'on_hand_milli' => 120000, 'short_milli' => 56400])
        ->and($needs['BTN-12L'])->toMatchArray(['need_milli' => 1680000, 'short_milli' => 1680000])
        // 240 XL pieces × 12 / 12 × 1.02 = 244.8 labels, rounded up to the thousandth.
        ->and($needs['LBL-XL']['need_milli'])->toBe(244800);
    expect(Requirements::need(1, 1, 0, 12))->toBe(1)->and(Requirements::need(0, 2400, 500, 12))->toBe(0);
});

it('lets the company add its own fields to styles and orders, required ones asked when making', function () {
    ($this->as)()->postJson(($this->fac)('fields'), ['entity' => 'order', 'key' => 'season', 'type' => 'choice', 'label' => ['en' => 'Season', 'bn' => 'মৌসুম'], 'is_required' => true,
        'options' => [['value' => 'ss27', 'label' => ['en' => 'Spring/Summer 27']], ['value' => 'aw27', 'label' => ['en' => 'Autumn/Winter 27']]]])->assertCreated();
    $gsm = ($this->as)()->postJson(($this->fac)('fields'), ['entity' => 'style', 'key' => 'gsm', 'type' => 'number', 'label' => ['en' => 'Fabric GSM']])->assertCreated()->json('data');

    ($this->order)()->assertUnprocessable()->assertJsonValidationErrors('extra.season');
    ($this->order)(extra: ['extra' => ['season' => 'winter']])->assertUnprocessable()->assertJsonValidationErrors('extra.season');
    expect(($this->order)(extra: ['extra' => ['season' => 'ss27']])->assertCreated()->json('data.extra'))->toBe(['season' => 'ss27']);
    expect(($this->as)()->patchJson(($this->fac)("styles/{$this->style['id']}"), ['extra' => ['gsm' => '180'], 'base_version' => 1])->assertOk()->json('data.extra'))->toBe(['gsm' => '180']);

    // Label can change and the field switch off; key and type stay.
    ($this->as)()->patchJson(($this->fac)("fields/{$gsm['id']}"), ['label' => ['en' => 'GSM'], 'is_active' => false, 'base_version' => 1])->assertOk()->assertJsonPath('data.is_active', false);
    ($this->as)()->patchJson(($this->fac)("fields/{$gsm['id']}"), ['type' => 'text', 'base_version' => 2])->assertUnprocessable()->assertJsonValidationErrors('type');
    expect(collect(($this->as)()->getJson(($this->fac)('setup'))->json('data.fields.order'))->pluck('key')->all())->toBe(['season']);
});

it('keeps the factory to its permissions, campus, company, and to the module and CRM being on', function () {
    $order = ($this->order)()->assertCreated()->json('data');
    $viewer = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['factory_erp.view'], 'Viewer'));
    $viewerToken = orgToken($viewer, $this->w->c1);
    ($this->as)($viewerToken)->getJson(($this->fac)('orders'))->assertOk()->assertJsonCount(1, 'data');
    ($this->as)($viewerToken)->postJson(($this->fac)("orders/{$order['id']}/confirm"), ['base_version' => 1])->assertForbidden();
    ($this->as)($viewerToken)->postJson(($this->fac)('fields'), ['entity' => 'style', 'key' => 'x1', 'type' => 'text', 'label' => ['en' => 'X']])->assertForbidden();

    // Orders belong to the unit they were made at and those above it.
    $other = createChild($this->w->c1, OrganizationType::Branch, 'Unit 2');
    expect(($this->as)()->getJson(($this->fac)('orders', $other))->assertOk()->json('data'))->toBe([]);
    ($this->as)()->getJson(($this->fac)("orders/{$order['id']}", $other))->assertNotFound();

    $stranger = createMember($this->w->c2);
    $this->asToken(orgToken($stranger, $this->w->c2))->getJson(($this->fac)("orders/{$order['id']}", $this->w->c2))->assertNotFound();
    $this->asToken(orgToken($stranger, $this->w->c2))->getJson(($this->fac)("styles/{$this->style['id']}", $this->w->c2))->assertNotFound();

    // CRM off takes the factory with it (it depends on CRM); turned on again, the data is all there.
    toggles()->disable($this->w->g1, 'crm', 'Test', confirm: true);
    ($this->as)()->getJson(($this->fac)('orders'))->assertForbidden();
    toggles()->enable($this->w->g1, 'factory_erp', 'Back');
    ($this->as)()->getJson(($this->fac)('orders'))->assertOk()->assertJsonCount(1, 'data');
});
