<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Offline\SyncOperation;
use Carbon\CarbonImmutable;
use Modules\Pos\Offline\SaleSync;

/*
 * Pharmacy: medicines on Inventory items, buying with batches and expiry,
 * selling by piece, strip or box through POS (never above MRP, never an
 * expired batch, controlled medicines only with a prescription, at the POS
 * till too), the controlled-drug register, supplier returns (maker-checker),
 * rules at company and branch, offline sales, the module off, and other
 * companies and partners.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        // A plan with every module (no listed plan names Pharmacy until the sector packages, H-5).
        setPlan($group, 'business');
        foreach (['inventory', 'pos', 'accounting', 'pharmacy'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    $role = fn (array $permissions, string $name) => staffWithRoles($this->w->c1, makeRole($this->w->c1, $permissions, $name));
    $this->keeperUser = $role(['inventory.view', 'inventory.manage', 'pos.view', 'pos.manage', 'accounting.view', 'accounting.manage', 'pharmacy.view', 'pharmacy.manage'], 'Store keeper');
    $this->keeper = orgToken($this->keeperUser, $this->w->c1);
    $this->pharmacistUser = $role(['inventory.view', 'pos.view', 'pos.sell', 'pharmacy.view', 'pharmacy.sell', 'pharmacy.controlled'], 'Pharmacist');
    $this->pharmacist = orgToken($this->pharmacistUser, $this->w->c1);
    $this->cashierUser = $role(['inventory.view', 'pos.view', 'pos.sell'], 'Cashier');
    $this->cashier = orgToken($this->cashierUser, $this->w->c1);
    $this->approver = orgToken($role(['pharmacy.view', 'pharmacy.approve', 'inventory.view'], 'Owner'), $this->w->c1);
    setUpBooks($this, $this->keeper, $this->w->c1)->assertCreated();

    $this->inv = fn (string $path) => "/api/organizations/{$this->w->c1->id}/inventory/{$path}";
    $this->pos = fn (string $path) => "/api/organizations/{$this->w->c1->id}/pos/{$path}";
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/pharmacy/{$path}";
    $this->shop = $this->asToken($this->keeper)->postJson(($this->inv)('warehouses'), ['code' => 'SHOP', 'name' => ['en' => 'Shop'], 'unit_id' => $this->w->c1->id])->assertCreated()->json('data');
    $this->till = $this->asToken($this->keeper)->postJson(($this->pos)('registers'), ['code' => 'T1', 'name' => ['en' => 'Counter 1'], 'unit_id' => $this->w->c1->id,
        'warehouse_id' => $this->shop['id'], 'payment_methods' => ['cash', 'card', 'mobile']])->assertCreated()->json('data');

    $generic = fn (string $name, bool $controlled = false) => $this->asToken($this->keeper)->postJson(($this->api)('generics'), ['name' => ['en' => $name, 'bn' => $name], 'is_controlled' => $controlled, 'in_use' => true])->assertCreated()->json('data');
    $this->paracetamol = $generic('Paracetamol');
    $this->diazepam = $generic('Diazepam', true);
    $this->medicine = fn (array $data) => $this->asToken($this->keeper)->postJson(($this->api)('medicines'), [
        'form' => 'tablet', 'strength' => '500 mg', 'pieces_per_strip' => 10, 'strips_per_box' => 10, ...$data,
    ]);
    // MRP 1.20 a piece, a strip of 10, a box of 10 strips.
    $this->napa = ($this->medicine)(['brand_name' => ['en' => 'Napa', 'bn' => 'নাপা'], 'sku' => 'NAPA500', 'barcode' => '8941100500011', 'generic_id' => $this->paracetamol['id'], 'mrp_minor' => 120])->assertCreated()->json('data');
    $this->ace = ($this->medicine)(['brand_name' => ['en' => 'Ace'], 'sku' => 'ACE500', 'generic_id' => $this->paracetamol['id'], 'mrp_minor' => 110])->assertCreated()->json('data');
    $this->sedil = ($this->medicine)(['brand_name' => ['en' => 'Sedil'], 'sku' => 'SEDIL5', 'strength' => '5 mg', 'generic_id' => $this->diazepam['id'], 'mrp_minor' => 500])->assertCreated()->json('data');

    $this->buy = fn (array $lines, string $on = '2026-11-01', ?string $token = null) => $this->asToken($token ?? $this->keeper)->postJson(($this->api)('purchases'), [
        'op_id' => (string) str()->ulid(), 'warehouse_id' => $this->shop['id'], 'supplier' => 'Beximco Distribution', 'purchased_on' => $on, 'lines' => $lines,
    ]);
    // Napa: batch A expired on 31 Oct, batch B expires on 20 Nov (soon), batch C next June.
    ($this->buy)([['medicine_id' => $this->napa['id'], 'pack' => 'strip', 'quantity' => 1, 'pack_cost_minor' => 900, 'batch_number' => 'A', 'expires_on' => '2026-10-31']], '2026-10-01')->assertCreated();
    ($this->buy)([
        ['medicine_id' => $this->napa['id'], 'pack' => 'strip', 'quantity' => 2, 'pack_cost_minor' => 900, 'batch_number' => 'B', 'expires_on' => '2026-11-20'],
        ['medicine_id' => $this->napa['id'], 'pack' => 'box', 'quantity' => 5, 'pack_cost_minor' => 9000, 'batch_number' => 'C', 'expires_on' => '2027-06-30'],
        ['medicine_id' => $this->sedil['id'], 'pack' => 'strip', 'quantity' => 2, 'pack_cost_minor' => 4000, 'batch_number' => 'S1', 'expires_on' => '2027-01-01'],
    ])->assertCreated();

    $this->asToken($this->pharmacist)->postJson(($this->pos)("registers/{$this->till['id']}/open"), ['opening_float_minor' => 100000])->assertCreated();
    $this->sell = fn (array $lines, int $paid = 1000000, array $extra = [], ?string $token = null) => $this->asToken($token ?? $this->pharmacist)->postJson(($this->api)('sales'), [
        'op_id' => (string) str()->ulid(), 'register_id' => $this->till['id'], 'lines' => $lines, 'payments' => [['method' => 'cash', 'amount_minor' => $paid]], ...$extra,
    ]);
    $this->tillSell = fn (string $itemId, int $pieces, ?string $token = null) => $this->asToken($token ?? $this->cashier)->postJson(($this->pos)('sales'), [
        'op_id' => (string) str()->ulid(), 'register_id' => $this->till['id'], 'lines' => [['item_id' => $itemId, 'quantity_milli' => $pieces * 1000]], 'payments' => [['method' => 'cash', 'amount_minor' => 1000000]],
    ]);
    $this->batch = fn (string $number) => collect($this->asToken($this->keeper)->getJson(($this->api)('stock'))->assertOk()->json('data'))->firstWhere('batch_number', $number);
    $this->prescription = ['prescription' => ['patient_name' => 'Karim Uddin', 'prescriber_name' => 'Dr. Salma', 'prescriber_bmdc' => 'A-12345']];
});

it('keeps medicines on Inventory items: priced within MRP, found by brand or generic, with substitutes', function () {
    expect($this->napa)->toMatchArray(['sku' => 'NAPA500', 'mrp_minor' => 120, 'sale_price_minor' => 120, 'pieces_per_strip' => 10, 'is_controlled' => false])
        ->and($this->sedil['is_controlled'])->toBeTrue();
    $catalogue = collect($this->asToken($this->cashier)->getJson(($this->pos)("registers/{$this->till['id']}/catalogue"))->assertOk()->json('data.items'))->keyBy('sku');
    expect($catalogue['NAPA500'])->toMatchArray(['sale_price_minor' => 120, 'track_batches' => true, 'on_hand_milli' => 10000 + 20000 + 500000]);

    ($this->medicine)(['brand_name' => ['en' => 'Dear'], 'sku' => 'DEAR', 'generic_id' => $this->paracetamol['id'], 'mrp_minor' => 100, 'sale_price_minor' => 150])
        ->assertUnprocessable()->assertJsonValidationErrors('sale_price_minor');
    ($this->medicine)(['brand_name' => ['en' => 'Dear'], 'sku' => 'DEAR', 'generic_id' => '01HZNOTAGENERIC00000000000', 'mrp_minor' => 100])->assertUnprocessable()->assertJsonPath('code', 'bad_link');
    ($this->medicine)(['sku' => 'NOPE', 'form' => 'potion'])->assertUnprocessable()->assertJsonValidationErrors(['brand_name', 'generic_id', 'form', 'mrp_minor']);
    $this->asToken($this->cashier)->postJson(($this->api)('medicines'), ['brand_name' => ['en' => 'X'], 'sku' => 'X1', 'generic_id' => $this->paracetamol['id'], 'form' => 'tablet', 'mrp_minor' => 10])->assertForbidden();

    $found = collect($this->asToken($this->pharmacist)->getJson(($this->api)('medicines?q=paracet'))->assertOk()->json('data'))->pluck('sku')->all();
    expect($found)->toContain('NAPA500', 'ACE500')->not->toContain('SEDIL5');
    expect(collect($this->asToken($this->pharmacist)->getJson(($this->api)('medicines?q=8941100500011'))->json('data'))->pluck('sku')->all())->toBe(['NAPA500']);
    expect(collect($this->asToken($this->pharmacist)->getJson(($this->api)("medicines/{$this->napa['id']}/substitutes"))->assertOk()->json('data'))->pluck('sku')->all())->toBe(['ACE500']);

    $changed = $this->asToken($this->keeper)->patchJson(($this->api)("medicines/{$this->napa['id']}"), ['sale_price_minor' => 110, 'base_version' => 1])->assertOk()->json('data');
    expect($changed)->toMatchArray(['sale_price_minor' => 110, 'version' => 2]);
    $this->asToken($this->keeper)->patchJson(($this->api)("medicines/{$this->napa['id']}"), ['mrp_minor' => 100, 'base_version' => 2])->assertUnprocessable()->assertJsonValidationErrors('sale_price_minor');
    $this->asToken($this->keeper)->patchJson(($this->api)("medicines/{$this->napa['id']}"), ['sale_price_minor' => 100, 'base_version' => 1])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    expect(AuditLog::query()->where('action', 'pharmacy.medicine_created')->count())->toBe(3);
});

it('sells strips and boxes from the good batch expiring first, never the expired one', function () {
    // Two strips (20 pieces at 1.20): batch A expired, so batch B.
    $op = (string) str()->ulid();
    $body = ['op_id' => $op, 'register_id' => $this->till['id'], 'lines' => [['medicine_id' => $this->napa['id'], 'pack' => 'strip', 'quantity' => 2]], 'payments' => [['method' => 'cash', 'amount_minor' => 3000]]];
    $sale = $this->asToken($this->pharmacist)->postJson(($this->api)('sales'), $body)->assertCreated()->json('data');
    expect($sale)->toMatchArray(['total_minor' => 2400, 'change_minor' => 600, 'has_controlled' => false]);
    expect($this->asToken($this->pharmacist)->postJson(($this->api)('sales'), $body)->assertOk()->json('data.id'))->toBe($sale['id']);
    expect(($this->batch)('A')['quantity_milli'])->toBe(10000)->and(($this->batch)('B'))->toBeNull()->and(($this->batch)('C')['quantity_milli'])->toBe(500000);

    // A box and three loose pieces, more than B ever held: all from C.
    ($this->sell)([['medicine_id' => $this->napa['id'], 'pack' => 'box', 'quantity' => 1], ['medicine_id' => $this->napa['id'], 'pack' => 'piece', 'quantity' => 3]])->assertCreated()
        ->assertJsonPath('data.total_minor', 103 * 120);
    expect(($this->batch)('C')['quantity_milli'])->toBe(500000 - 103000)->and(($this->batch)('A')['quantity_milli'])->toBe(10000);

    // The POS receipt is the same sale; the pharmacy keeps its record.
    $this->asToken($this->pharmacist)->getJson(($this->pos)("sales/{$sale['pos_sale_id']}"))->assertOk()->assertJsonPath('data.total_minor', 2400);
    expect($this->asToken($this->pharmacist)->getJson(($this->api)('sales'))->assertOk()->json('meta.total'))->toBe(2);
    ($this->sell)([['medicine_id' => $this->ace['id'], 'pack' => 'strip', 'quantity' => 1]])->assertConflict()->assertJsonPath('code', 'insufficient_stock');
});

it('refuses expired stock and prices above MRP at the POS till too, while Pharmacy is on', function () {
    // Only batch A (expired) of Napa left after B and C are sold out.
    ($this->sell)([['medicine_id' => $this->napa['id'], 'pack' => 'strip', 'quantity' => 52]])->assertCreated();
    ($this->sell)([['medicine_id' => $this->napa['id'], 'pack' => 'piece', 'quantity' => 1]])->assertConflict()->assertJsonPath('code', 'expired_stock');
    ($this->tillSell)($this->napa['item_id'], 1)->assertConflict()->assertJsonPath('code', 'expired_stock');

    // The company lets expired batches go (its own decision): the till sells batch A.
    trustedOrgRule($this->w->c1, 'pharmacy.block_expired_sale', false);
    ($this->tillSell)($this->napa['item_id'], 1)->assertCreated();
    expect(($this->batch)('A')['quantity_milli'])->toBe(9000);

    // Someone raises the price in Inventory above the MRP: refused at the till.
    $item = collect($this->asToken($this->keeper)->getJson(($this->inv)('items'))->json('data'))->firstWhere('sku', 'SEDIL5');
    $this->asToken($this->keeper)->patchJson(($this->inv)("items/{$item['id']}"), ['sale_price_minor' => 900, 'base_version' => $item['version']])->assertOk();
    ($this->tillSell)($this->sedil['item_id'], 1)->assertUnprocessable()->assertJsonPath('code', 'above_mrp');

    // Pharmacy off at the company: the till is POS alone again; the pharmacy's screens answer 403.
    toggles()->disable($this->w->c1, 'pharmacy', 'Test');
    ($this->tillSell)($this->sedil['item_id'], 1)->assertCreated();
    $this->asToken($this->pharmacist)->getJson(($this->api)('medicines'))->assertForbidden();
    ($this->sell)([['medicine_id' => $this->ace['id'], 'pack' => 'strip', 'quantity' => 1]])->assertForbidden();
});

it('sells controlled medicines only with a prescription, and writes them into the register', function () {
    ($this->sell)([['medicine_id' => $this->sedil['id'], 'pack' => 'strip', 'quantity' => 1]])->assertUnprocessable()->assertJsonPath('code', 'prescription_needed');
    ($this->sell)([['medicine_id' => $this->sedil['id'], 'pack' => 'strip', 'quantity' => 1]], extra: ['prescription' => ['patient_name' => 'Karim', 'prescriber_bmdc' => 'not a number']])
        ->assertUnprocessable()->assertJsonValidationErrors('prescription.prescriber_bmdc');
    // The POS till cannot pass a prescription at all.
    ($this->tillSell)($this->sedil['item_id'], 1)->assertUnprocessable()->assertJsonPath('code', 'prescription_needed');

    $sale = ($this->sell)([['medicine_id' => $this->sedil['id'], 'pack' => 'piece', 'quantity' => 4], ['medicine_id' => $this->napa['id'], 'pack' => 'strip', 'quantity' => 1]], extra: $this->prescription)
        ->assertCreated()->json('data');
    expect($sale)->toMatchArray(['has_controlled' => true, 'total_minor' => 4 * 500 + 1200])->and($sale['prescription']['prescriber_bmdc'])->toBe('A-12345');

    $register = $this->asToken($this->pharmacist)->getJson(($this->api)("controlled-register?medicine_id={$this->sedil['id']}"))->assertOk()->json('data');
    expect(array_map(fn ($entry) => [$entry['direction'], $entry['source_type'], $entry['batch_number'], $entry['quantity_milli'], $entry['balance_after_milli']], $register))->toBe([
        ['in', 'purchase', 'S1', 20000, 20000],
        ['out', 'sale', 'S1', 4000, 16000],
    ])->and($register[1]['patient_name'])->toBe('Karim Uddin');
    expect(AuditLog::query()->where('action', 'pharmacy.controlled_viewed')->count())->toBe(1);

    // The register and the prescriptions are for pharmacy.controlled only.
    $this->asToken($this->keeper)->getJson(($this->api)('controlled-register'))->assertForbidden();
    expect($this->asToken($this->keeper)->getJson(($this->api)('sales?controlled=1'))->assertOk()->json('data.0.prescription'))->toBeNull();

    // The company may decide controlled medicines need no prescription (a sensitive rule).
    trustedOrgRule($this->w->c1, 'pharmacy.controlled_requires_prescription', false);
    ($this->sell)([['medicine_id' => $this->sedil['id'], 'pack' => 'piece', 'quantity' => 1]])->assertCreated();
});

it('follows the rules at the company and the branch: loose pieces, near expiry', function () {
    $counter = $this->asToken($this->pharmacist)->getJson(($this->api)("counter/{$this->till['id']}"))->assertOk();
    $napa = collect($counter->json('data'))->firstWhere('medicine_id', $this->napa['id']);
    // Good stock only (A expired), first expiry B on 20 Nov: within 30 days.
    expect($napa)->toMatchArray(['sellable_milli' => 520000, 'expired_milli' => 10000, 'first_batch' => 'B', 'first_expiry' => '2026-11-20', 'near_expiry' => true])
        ->and($counter->json('meta.sell_loose_pieces'))->toBeTrue();

    orgRule($this->w->c1, 'pharmacy.near_expiry_days', 10);
    expect(collect($this->asToken($this->pharmacist)->getJson(($this->api)("counter/{$this->till['id']}"))->json('data'))->firstWhere('medicine_id', $this->napa['id'])['near_expiry'])->toBeFalse();
    expect(collect($this->asToken($this->keeper)->getJson(($this->api)('stock?show=expired'))->json('data'))->pluck('batch_number')->all())->toBe(['A']);
    expect(collect($this->asToken($this->keeper)->getJson(($this->api)('stock?show=return'))->json('data'))->pluck('batch_number')->all())->toBe(['A', 'B', 'S1']);

    orgRule($this->w->c1, 'pharmacy.sell_loose_pieces', false);
    ($this->sell)([['medicine_id' => $this->napa['id'], 'pack' => 'piece', 'quantity' => 2]])->assertUnprocessable()->assertJsonPath('code', 'loose_pieces');
    ($this->sell)([['medicine_id' => $this->napa['id'], 'pack' => 'strip', 'quantity' => 1]])->assertCreated();
});

it('buys with batch and expiry, and sends returns that someone else approves', function () {
    ($this->buy)([['medicine_id' => $this->ace['id'], 'pack' => 'box', 'quantity' => 1, 'pack_cost_minor' => 8000, 'batch_number' => 'X']])->assertUnprocessable()->assertJsonValidationErrors('lines.0.expires_on');
    ($this->buy)([['medicine_id' => $this->ace['id'], 'pack' => 'box', 'quantity' => 1, 'pack_cost_minor' => 8000, 'expires_on' => '2027-01-01']])->assertUnprocessable()->assertJsonValidationErrors('lines.0.batch_number');
    ($this->buy)([['medicine_id' => $this->ace['id'], 'pack' => 'box', 'quantity' => 1, 'pack_cost_minor' => 8000, 'batch_number' => 'X', 'expires_on' => '2027-01-01']], token: $this->pharmacist)->assertForbidden();
    $purchase = ($this->buy)([['medicine_id' => $this->ace['id'], 'pack' => 'box', 'quantity' => 1, 'pack_cost_minor' => 8000, 'batch_number' => 'X', 'expires_on' => '2027-01-01']])->assertCreated()->json('data');
    expect($purchase)->toMatchArray(['total_minor' => 8000, 'line_count' => 1, 'supplier' => 'Beximco Distribution'])->and($purchase['document_number'])->not->toBeNull();

    $return = $this->asToken($this->keeper)->postJson(($this->api)('supplier-returns'), ['op_id' => (string) str()->ulid(), 'warehouse_id' => $this->shop['id'], 'supplier' => 'Beximco Distribution',
        'reason' => 'Expired', 'lines' => [['medicine_id' => $this->napa['id'], 'batch_number' => 'A', 'quantity' => 10]]])->assertCreated()->json('data');
    expect($return)->toMatchArray(['status' => 'submitted', 'number' => null, 'mine' => true]);
    $this->asToken($this->keeper)->postJson(($this->api)('supplier-returns'), ['op_id' => (string) str()->ulid(), 'warehouse_id' => $this->shop['id'], 'supplier' => 'X',
        'reason' => 'Too many', 'lines' => [['medicine_id' => $this->napa['id'], 'batch_number' => 'A', 'quantity' => 11]]])->assertConflict()->assertJsonPath('code', 'batch_short');
    expect(collect($this->asToken($this->approver)->getJson('/api/attention')->json('data'))->pluck('key')->all())->toContain('pharmacy.returns', 'pharmacy.expired');

    $this->asToken($this->pharmacist)->postJson(($this->api)("supplier-returns/{$return['id']}/approve"), ['base_version' => 1])->assertForbidden();
    $this->asToken($this->approver)->postJson(($this->api)("supplier-returns/{$return['id']}/reject"), ['base_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('note');
    $approved = $this->asToken($this->approver)->postJson(($this->api)("supplier-returns/{$return['id']}/approve"), ['base_version' => 1])->assertOk()->json('data');
    expect($approved)->toMatchArray(['status' => 'approved', 'number' => 'SR-2026-000001'])->and(($this->batch)('A'))->toBeNull();
    $this->asToken($this->approver)->postJson(($this->api)("supplier-returns/{$return['id']}/approve"), ['base_version' => 2])->assertConflict()->assertJsonPath('code', 'decided');

    // The person who sent it cannot approve it.
    $keeperApprover = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['pharmacy.view', 'pharmacy.manage', 'pharmacy.approve', 'inventory.view'], 'Manager')), $this->w->c1);
    $own = $this->asToken($keeperApprover)->postJson(($this->api)('supplier-returns'), ['op_id' => (string) str()->ulid(), 'warehouse_id' => $this->shop['id'], 'supplier' => 'Beximco Distribution',
        'reason' => 'Near expiry', 'lines' => [['medicine_id' => $this->napa['id'], 'batch_number' => 'B', 'quantity' => 5]]])->assertCreated()->json('data');
    $this->asToken($keeperApprover)->postJson(($this->api)("supplier-returns/{$own['id']}/approve"), ['base_version' => 1])->assertForbidden()->assertJsonPath('code', 'own_approval');

    $report = $this->asToken($this->approver)->getJson(($this->api)('reports?kind=expiry'))->assertOk()->json('data.rows');
    expect(collect($report)->firstWhere('state', 'near')['worth_minor'])->toBe(20 * 120);
    $this->asToken($this->pharmacist)->getJson(($this->api)('reports?kind=sales&from=2026-11-01&to=2026-11-02'))->assertForbidden();
});

it('keeps a sale made offline at the till, marking what broke a pharmacy rule', function () {
    $session = $this->asToken($this->pharmacist)->getJson(($this->pos)('sessions?status=open'))->json('data.0');
    $apply = function (array $lines, string $op) {
        $this->asToken($this->pharmacist)->getJson(($this->pos)('registers'))->assertOk();

        return app(SaleSync::class)->apply(new SyncOperation($op, 'pos.sale', SyncOperation::CREATE, null, null,
            ['register_id' => $this->till['id'], 'session_id' => null, 'lines' => $lines, 'payments' => [['method' => 'cash', 'amount_minor' => 1000000]]],
            CarbonImmutable::parse('2026-11-02 03:00:00', 'UTC'), $this->pharmacistUser, $this->w->c1));
    };
    expect($session)->not->toBeNull();

    // 30 pieces of Napa: 20 from B, 10 from C; nothing from the expired batch.
    $fine = $apply([['item_id' => $this->napa['item_id'], 'quantity_milli' => 30000]], '01HZOFFLINEPHARMA000000001');
    expect($fine->status)->toBe('applied')
        ->and($this->asToken($this->pharmacist)->getJson(($this->pos)("sales/{$fine->recordId}"))->json('data.review_reason'))->toBeNull()
        ->and(($this->batch)('A')['quantity_milli'])->toBe(10000)->and(($this->batch)('C')['quantity_milli'])->toBe(490000);

    // A controlled medicine sold offline (no prescription can come from the till): kept, for review.
    $marked = $apply([['item_id' => $this->sedil['item_id'], 'quantity_milli' => 1000]], '01HZOFFLINEPHARMA000000002');
    expect($marked->status)->toBe('applied')
        ->and($this->asToken($this->pharmacist)->getJson(($this->pos)("sales/{$marked->recordId}"))->json('data.review_reason'))->toBe('guard');
});

it('keeps medicines, sales and the register to the company and its partner', function () {
    $sameGroup = orgToken(createMember($this->w->c2), $this->w->c2);
    $otherPartner = orgToken(createMember($this->w->c4), $this->w->c4);
    foreach ([[$sameGroup, $this->w->c2], [$otherPartner, $this->w->c4]] as [$token, $company]) {
        $this->asToken($token)->getJson(($this->api)("medicines/{$this->napa['id']}", $company))->assertNotFound();
        $this->asToken($token)->getJson(($this->api)("counter/{$this->till['id']}", $company))->assertNotFound();
        expect($this->asToken($token)->getJson(($this->api)('medicines', $company))->assertOk()->json('meta.total'))->toBe(0)
            ->and($this->asToken($token)->getJson(($this->api)('controlled-register', $company))->assertOk()->json('data'))->toBe([]);
        // Their address, not ours: never reached.
        $this->asToken($token)->getJson(($this->api)('medicines'))->assertNotFound();
        $this->asToken($token)->postJson(($this->api)('sales', $company), [
            'op_id' => (string) str()->ulid(), 'register_id' => $this->till['id'], 'lines' => [['medicine_id' => $this->napa['id'], 'pack' => 'strip', 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount_minor' => 5000]],
        ])->assertNotFound();
    }
});
