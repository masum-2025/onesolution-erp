<?php

use App\Platform\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Modules\Inventory\Events\StockRanLow;
use Modules\Inventory\Services\Stock;

/*
 * INV-1: the item list, the stock ledger (weighted average and FIFO,
 * batches out by expiry), receipts, issues, transfers with losses,
 * adjustments above a limit approved by someone else, counts, posting to
 * the books, the public Stock service, the bell, and isolation.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2] as $group) {
        toggles()->enable($group, 'inventory', 'Test setup');
        toggles()->enable($group, 'accounting', 'Test setup');
    }
    $this->keeper = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['inventory.view', 'inventory.manage'], 'Store keeper')), $this->w->c1);
    $this->approver = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['inventory.view', 'inventory.approve'], 'Stock approver')), $this->w->c1);
    $this->books = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['accounting.view', 'accounting.manage'], 'Books')), $this->w->c1);
    setUpBooks($this, $this->books, $this->w->c1)->assertCreated();
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/inventory/{$path}";
    $post = fn (string $path, array $data) => $this->asToken($this->keeper)->postJson(($this->api)($path), $data)->assertCreated()->json('data');

    $this->pcs = $post('units', ['code' => 'PCS', 'name' => ['en' => 'Pieces', 'bn' => 'পিস'], 'decimals' => 0]);
    $kg = $post('units', ['code' => 'KG', 'name' => ['en' => 'Kilogram'], 'decimals' => 3]);
    $this->main = $post('warehouses', ['code' => 'MAIN', 'name' => ['en' => 'Main store'], 'unit_id' => $this->w->c1->id]);
    $this->shop = $post('warehouses', ['code' => 'SHOP', 'name' => ['en' => 'Branch shop'], 'unit_id' => $this->w->b1->id]);
    $this->soap = $post('items', ['sku' => 'SOAP-01', 'barcode' => '8901234567890', 'name' => ['en' => 'Soap', 'bn' => 'সাবান'], 'unit_id' => $this->pcs['id'], 'kind' => 'stock', 'sale_price_minor' => 15000, 'reorder_level_milli' => 5000]);
    $this->rice = $post('items', ['sku' => 'RICE', 'name' => ['en' => 'Rice'], 'unit_id' => $kg['id'], 'kind' => 'stock']);
    $this->milk = $post('items', ['sku' => 'MILK', 'name' => ['en' => 'Milk powder'], 'unit_id' => $this->pcs['id'], 'kind' => 'stock', 'track_batches' => true]);
    $this->repair = $post('items', ['sku' => 'SRV-REPAIR', 'name' => ['en' => 'Repair service'], 'unit_id' => $this->pcs['id'], 'kind' => 'non_stock', 'sale_price_minor' => 50000]);

    $this->document = fn (string $type, array $lines, array $extra = []) => $post('documents', [
        'type' => $type, 'warehouse_id' => $this->main['id'], 'document_date' => '2026-11-01', 'lines' => $lines, ...$extra,
    ]);
    $this->step = fn (array $document, string $step, ?string $token = null, array $data = []) => $this->asToken($token ?? $this->keeper)
        ->postJson(($this->api)("documents/{$document['id']}/{$step}"), ['base_version' => $document['version'], ...$data]);
    $this->receive = fn (array $lines) => ($this->step)(($this->document)('receipt', $lines, ['counterparty' => 'Square Toiletries', 'reference' => 'INV-778']), 'post')->assertOk()->json('data');
    $this->balance = fn (array $item, ?array $warehouse = null) => collect($this->asToken($this->keeper)->getJson(($this->api)('stock'))->json('data'))
        ->first(fn ($row) => $row['item_id'] === $item['id'] && $row['warehouse_id'] === ($warehouse ?? $this->main)['id']);
    $this->row = fn (string $code) => collect($this->asToken($this->books)->getJson("/api/organizations/{$this->w->c1->id}/accounting/reports/trial-balance?as_of=2026-11-02")->json('data.rows'))->firstWhere('code', $code);
});

it('keeps the item list with unique codes, decimals per unit, and items fixed once moved', function () {
    $this->asToken($this->keeper)->postJson(($this->api)('items'), ['sku' => 'SOAP-01', 'name' => ['en' => 'Again'], 'unit_id' => $this->pcs['id'], 'kind' => 'stock'])
        ->assertUnprocessable()->assertJsonValidationErrors('sku');
    $this->asToken($this->keeper)->getJson(($this->api)('lookup?code=8901234567890'))->assertOk()->assertJsonPath('data.sku', 'SOAP-01');
    expect($this->asToken($this->keeper)->getJson(($this->api)('items?search=সাবান'))->json('data'))->toHaveCount(1);

    ($this->document)('receipt', [['item_id' => $this->rice['id'], 'quantity_milli' => 1234, 'unit_cost_minor' => 8000]]);
    $this->asToken($this->keeper)->postJson(($this->api)('documents'), ['type' => 'receipt', 'warehouse_id' => $this->main['id'], 'document_date' => '2026-11-01',
        'lines' => [['item_id' => $this->soap['id'], 'quantity_milli' => 1500, 'unit_cost_minor' => 100]]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity_milli');
    $this->asToken($this->keeper)->postJson(($this->api)('documents'), ['type' => 'issue', 'warehouse_id' => $this->main['id'], 'document_date' => '2026-11-01',
        'lines' => [['item_id' => $this->repair['id'], 'quantity_milli' => 1000]]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.item_id');

    ($this->receive)([['item_id' => $this->soap['id'], 'quantity_milli' => 10000, 'unit_cost_minor' => 10000]]);
    $this->asToken($this->keeper)->patchJson(($this->api)("items/{$this->soap['id']}"), ['base_version' => $this->soap['version'], 'kind' => 'non_stock'])
        ->assertUnprocessable()->assertJsonValidationErrors('unit_id');
    expect(AuditLog::query()->where('action', 'inventory.item_created')->count())->toBe(4);
});

it('values stock at the weighted average, posts receipts against goods not billed and issues to cost of goods', function () {
    ($this->receive)([['item_id' => $this->soap['id'], 'quantity_milli' => 10000, 'unit_cost_minor' => 10000]]);
    $receipt = ($this->receive)([['item_id' => $this->soap['id'], 'quantity_milli' => 10000, 'unit_cost_minor' => 12000]]);
    expect($receipt)->toMatchArray(['status' => 'posted', 'number' => 'GRN-2026-00002', 'value_minor' => 120000])->and($receipt['journal_id'])->not->toBeNull();
    expect(($this->balance)($this->soap))->toMatchArray(['quantity_milli' => 20000, 'value_minor' => 220000, 'unit_cost_minor' => 11000]);

    $issue = ($this->step)(($this->document)('issue', [['item_id' => $this->soap['id'], 'quantity_milli' => 5000]]), 'post')->assertOk()->json('data');
    expect($issue['value_minor'])->toBe(-55000);
    ($this->step)(($this->document)('issue', [['item_id' => $this->soap['id'], 'quantity_milli' => 16000]]), 'post')
        ->assertConflict()->assertJsonPath('code', 'insufficient_stock');

    expect(($this->row)('1150')['debit_minor'])->toBe(165000)
        ->and(($this->row)('2115')['credit_minor'])->toBe(220000)
        ->and(($this->row)('5100')['debit_minor'])->toBe(55000);
});

it('values stock first in, first out where the rule says so', function () {
    trustedOrgRule($this->w->c1, 'inventory.valuation_method', 'FIFO');
    ($this->receive)([['item_id' => $this->soap['id'], 'quantity_milli' => 10000, 'unit_cost_minor' => 10000]]);
    ($this->receive)([['item_id' => $this->soap['id'], 'quantity_milli' => 10000, 'unit_cost_minor' => 12000]]);
    $issue = ($this->step)(($this->document)('issue', [['item_id' => $this->soap['id'], 'quantity_milli' => 15000]]), 'post')->assertOk()->json('data');
    expect($issue['value_minor'])->toBe(-(100000 + 60000))
        ->and(($this->balance)($this->soap))->toMatchArray(['quantity_milli' => 5000, 'value_minor' => 60000, 'method' => 'FIFO']);
});

it('transfers between warehouses at cost; what does not arrive is a loss', function () {
    ($this->receive)([['item_id' => $this->soap['id'], 'quantity_milli' => 10000, 'unit_cost_minor' => 10000]]);
    $transfer = ($this->document)('transfer', [['item_id' => $this->soap['id'], 'quantity_milli' => 4000]], ['to_warehouse_id' => $this->shop['id']]);
    ($this->step)($transfer, 'post')->assertConflict();
    $transfer = ($this->step)($transfer, 'dispatch')->assertOk()->json('data');
    expect($transfer)->toMatchArray(['status' => 'in_transit', 'value_minor' => 40000])
        ->and(($this->balance)($this->soap)['quantity_milli'])->toBe(6000);

    $line = $transfer['lines'][0]['id'];
    ($this->step)($transfer, 'receive', null, ['received' => [$line => 5000]])->assertUnprocessable();
    $transfer = ($this->step)($transfer, 'receive', null, ['received' => [$line => 3000]])->assertOk()->json('data');
    expect($transfer['status'])->toBe('posted')
        ->and(($this->balance)($this->soap, $this->shop))->toMatchArray(['quantity_milli' => 3000, 'value_minor' => 30000])
        ->and(($this->row)('5900')['debit_minor'])->toBe(10000)
        ->and(($this->row)('1150')['debit_minor'])->toBe(90000);
});

it('holds an adjustment above the limit for someone else, and posts it against adjustments', function () {
    trustedOrgRule($this->w->c1, 'inventory.adjustment_approval_above', ['amount' => 50000, 'currency' => 'BDT']);
    ($this->receive)([['item_id' => $this->soap['id'], 'quantity_milli' => 10000, 'unit_cost_minor' => 10000]]);

    $small = ($this->step)(($this->document)('adjustment', [['item_id' => $this->soap['id'], 'quantity_milli' => -2000]], ['reason' => 'Broken in the store']), 'post')->assertOk()->json('data');
    expect($small)->toMatchArray(['status' => 'posted', 'value_minor' => -20000]);

    $big = ($this->step)(($this->document)('adjustment', [['item_id' => $this->soap['id'], 'quantity_milli' => -6000]], ['reason' => 'Water damage']), 'post')->assertOk()->json('data');
    expect($big['status'])->toBe('pending_approval')->and(($this->balance)($this->soap)['quantity_milli'])->toBe(8000);
    ($this->step)($big, 'approve')->assertForbidden();
    ($this->step)($big, 'reject', $this->approver)->assertUnprocessable()->assertJsonValidationErrors('reason');
    $big = ($this->step)($big, 'approve', $this->approver)->assertOk()->json('data');
    expect($big)->toMatchArray(['status' => 'posted', 'value_minor' => -60000])
        ->and(($this->row)('5900')['debit_minor'])->toBe(80000);

    $this->asToken($this->keeper)->postJson(($this->api)('documents'), ['type' => 'adjustment', 'warehouse_id' => $this->main['id'], 'document_date' => '2026-11-01',
        'lines' => [['item_id' => $this->soap['id'], 'quantity_milli' => -1000]]])->assertUnprocessable()->assertJsonValidationErrors('reason');
});

it('tracks batches: needs a number coming in, takes the one expiring first going out, and warns of expiry', function () {
    $this->asToken($this->keeper)->postJson(($this->api)('documents'), ['type' => 'receipt', 'warehouse_id' => $this->main['id'], 'document_date' => '2026-11-01',
        'lines' => [['item_id' => $this->milk['id'], 'quantity_milli' => 1000, 'unit_cost_minor' => 50000]]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.batch_number');

    ($this->receive)([
        ['item_id' => $this->milk['id'], 'quantity_milli' => 6000, 'unit_cost_minor' => 50000, 'batch_number' => 'LATE', 'expires_on' => '2027-06-30'],
        ['item_id' => $this->milk['id'], 'quantity_milli' => 4000, 'unit_cost_minor' => 50000, 'batch_number' => 'SOON', 'expires_on' => '2026-11-20'],
    ]);
    ($this->step)(($this->document)('issue', [['item_id' => $this->milk['id'], 'quantity_milli' => 5000]]), 'post')->assertOk();
    $batches = collect($this->asToken($this->keeper)->getJson(($this->api)("items/{$this->milk['id']}"))->assertOk()->json('data.batches'))->pluck('quantity_milli', 'number');
    expect($batches->all())->toBe(['LATE' => 5000]);

    expect($this->asToken($this->keeper)->getJson(($this->api)('expiring'))->json('data'))->toBe([]);
    ($this->receive)([['item_id' => $this->milk['id'], 'quantity_milli' => 1000, 'unit_cost_minor' => 50000, 'batch_number' => 'SOON2', 'expires_on' => '2026-11-20']]);
    expect(collect($this->asToken($this->keeper)->getJson(($this->api)('expiring'))->json('data'))->pluck('number')->all())->toBe(['SOON2']);
});

it('counts stock: the differences approved by someone else are posted', function () {
    ($this->receive)([['item_id' => $this->soap['id'], 'quantity_milli' => 10000, 'unit_cost_minor' => 10000]]);
    $count = $this->asToken($this->keeper)->postJson(($this->api)('counts'), ['warehouse_id' => $this->main['id'], 'counted_on' => '2026-11-02'])->assertCreated()->json('data');
    expect($count['lines'])->toHaveCount(1)->and($count['lines'][0])->toMatchArray(['item_id' => $this->soap['id'], 'expected_milli' => 10000, 'counted_milli' => null]);
    $this->asToken($this->keeper)->postJson(($this->api)('counts'), ['warehouse_id' => $this->main['id'], 'counted_on' => '2026-11-02'])->assertConflict()->assertJsonPath('code', 'count_open');

    $count = $this->asToken($this->keeper)->patchJson(($this->api)("counts/{$count['id']}"), ['base_version' => $count['version'], 'lines' => [
        ['item_id' => $this->soap['id'], 'counted_milli' => 9000], ['item_id' => $this->rice['id'], 'counted_milli' => 2500],
    ]])->assertOk()->json('data');
    $count = $this->asToken($this->keeper)->postJson(($this->api)("counts/{$count['id']}/submit"), ['base_version' => $count['version']])->assertOk()->json('data');
    $this->asToken($this->keeper)->postJson(($this->api)("counts/{$count['id']}/approve"), ['base_version' => $count['version']])->assertForbidden();
    $count = $this->asToken($this->approver)->postJson(($this->api)("counts/{$count['id']}/approve"), ['base_version' => $count['version']])->assertOk()->json('data');

    // One soap missing (−100.00); rice found with no cost known (0).
    expect($count)->toMatchArray(['status' => 'posted', 'variance_value_minor' => -10000])
        ->and(($this->balance)($this->soap)['quantity_milli'])->toBe(9000)
        ->and(($this->balance)($this->rice)['quantity_milli'])->toBe(2500)
        ->and(($this->row)('5900')['debit_minor'])->toBe(10000);
});

it('lets other modules take stock once per record and bring it back, without posting', function () {
    ($this->receive)([['item_id' => $this->soap['id'], 'quantity_milli' => 10000, 'unit_cost_minor' => 10000]]);
    $stock = app(Stock::class);
    $source = ['module' => 'pos', 'type' => 'sale', 'id' => '01HZSALE000000000000000001'];
    $taken = $stock->take($this->w->c1, $this->main['id'], [['item_id' => $this->soap['id'], 'quantity_milli' => 3000], ['item_id' => $this->repair['id'], 'quantity_milli' => 1000]], $source, CarbonImmutable::now());
    expect($taken)->toMatchArray(['lines' => [30000, 0], 'cost_minor' => 30000, 'moved' => true]);
    expect($stock->take($this->w->c1, $this->main['id'], [['item_id' => $this->soap['id'], 'quantity_milli' => 3000]], $source, CarbonImmutable::now()))->toMatchArray(['cost_minor' => 30000, 'moved' => false]);

    $back = $stock->take($this->w->c1, $this->main['id'], [['item_id' => $this->soap['id'], 'quantity_milli' => -1000]], ['module' => 'pos', 'type' => 'return', 'id' => '01HZRETURN0000000000000001'], CarbonImmutable::now());
    expect($back['cost_minor'])->toBe(-10000)
        ->and($stock->onHand($this->w->c1, $this->main['id'], [$this->soap['id']]))->toBe([$this->soap['id'] => 8000])
        ->and(($this->row)('5100'))->toBeNull();
    expect(collect($stock->items($this->w->c1, null, '8901234567890'))->pluck('sku')->all())->toBe(['SOAP-01']);
});

it('rings the bell for stock at its reorder level and keeps stock to the company and its units', function () {
    Event::fake([StockRanLow::class]);
    ($this->receive)([['item_id' => $this->soap['id'], 'quantity_milli' => 8000, 'unit_cost_minor' => 10000]]);
    ($this->step)(($this->document)('issue', [['item_id' => $this->soap['id'], 'quantity_milli' => 4000]]), 'post')->assertOk();
    Event::assertDispatched(StockRanLow::class, fn (StockRanLow $event) => $event->itemId === $this->soap['id'] && $event->quantityMilli === 4000);
    $bell = collect($this->asToken($this->keeper)->getJson('/api/attention')->json('data'))->pluck('count', 'key');
    expect($bell['inventory.low_stock'] ?? null)->toBe(1);
    expect($this->asToken($this->keeper)->getJson(($this->api)('stock?low=1'))->json('data'))->toHaveCount(1);

    // Another company sees none of it.
    $c2 = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['inventory.view', 'inventory.manage'], 'C2 store')), $this->w->c2);
    expect($this->asToken($c2)->getJson(($this->api)('items', $this->w->c2))->json('data'))->toBe([]);
    $this->asToken($c2)->getJson(($this->api)("items/{$this->soap['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($c2)->postJson(($this->api)('documents', $this->w->c2), ['type' => 'issue', 'warehouse_id' => $this->main['id'], 'document_date' => '2026-11-01',
        'lines' => [['item_id' => $this->soap['id'], 'quantity_milli' => 1000]]])->assertNotFound();

    // At the branch, only the branch's warehouse.
    $branch = orgToken(staffWithRoles($this->w->b1, makeRole($this->w->c1, ['inventory.view', 'inventory.manage'], 'Branch store')), $this->w->b1);
    expect(collect($this->asToken($branch)->getJson(($this->api)('warehouses', $this->w->b1))->json('data'))->pluck('code')->all())->toBe(['SHOP']);
    $this->asToken($branch)->postJson(($this->api)('documents', $this->w->b1), ['type' => 'issue', 'warehouse_id' => $this->main['id'], 'document_date' => '2026-11-01',
        'lines' => [['item_id' => $this->soap['id'], 'quantity_milli' => 1000]]])->assertNotFound();

    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view'], 'Not stock')), $this->w->c1);
    $this->asToken($viewer)->getJson(($this->api)('stock'))->assertForbidden();
    toggles()->disable($this->w->g1, 'inventory', 'Test setup', confirm: true);
    $this->asToken($this->keeper)->getJson(($this->api)('stock'))->assertForbidden();
});
