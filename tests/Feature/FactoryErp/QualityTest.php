<?php

use App\Platform\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Modules\FactoryErp\Services\Quality;

/*
 * FAC-3b: the company's kinds of defect (a starter list), end-line checks
 * and DHU, final inspections from the AQL table, shipping only after a
 * passed final, the dashboard and bell, and who may do what: permission,
 * other companies, the module off.
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
    $this->inspector = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['factory_erp.view', 'factory_erp.qc'], 'QC inspector')), $this->w->c1);

    $buyer = ($this->as)()->postJson(($this->fac)('buyers'), ['name' => 'Nordic Wear AB'])->assertCreated()->json('data');
    $style = ($this->as)()->postJson(($this->fac)('styles'), ['buyer_id' => $buyer['id'], 'code' => 'NW-2611', 'name' => 'Basic polo', 'sizes' => ['M'], 'colors' => ['Navy']])->assertCreated()->json('data');
    $this->order = ($this->as)()->postJson(($this->fac)('orders'), ['style_id' => $style['id'], 'ship_on' => '2026-12-15', 'lines' => [['size' => 'M', 'color' => 'Navy', 'quantity' => 1000]]])
        ->assertCreated()->json('data');
    ($this->as)()->postJson(($this->fac)("orders/{$this->order['id']}/confirm"), ['base_version' => 1])->assertOk();
    ($this->as)()->postJson(($this->fac)("orders/{$this->order['id']}/cuttings"), ['lines' => [['size' => 'M', 'color' => 'Navy', 'quantity' => 1000]]])->assertCreated();
    $this->line = ($this->as)()->postJson(($this->fac)('lines'), ['code' => 'L-01', 'name' => 'Line 1'])->assertCreated()->json('data');
    $this->types = collect(($this->as)()->getJson(($this->fac)('defect-types'))->assertOk()->json('data'))->keyBy('code');
    $this->inspect = fn (array $data, ?string $token = null) => ($this->as)($token ?? $this->inspector)->postJson(($this->fac)('inspections'), ['order_id' => $this->order['id'], ...$data]);
    $this->defects = fn (array $counts) => collect($counts)->map(fn (int $count, string $code) => ['defect_type_id' => $this->types[$code]['id'], 'count' => $count])->values()->all();
});

it('starts a company with common kinds of defect it can rename, add to and switch off', function () {
    expect($this->types->keys()->all())->toBe(['SKIP', 'BROKEN', 'OPEN', 'SPOT', 'MEASURE'])
        ->and($this->types['SKIP']['names']['en'])->toBe('Skip stitch')
        ->and(Normalizer::normalize($this->types['SKIP']['names']['bn']))->toBe(Normalizer::normalize('সেলাই বাদ পড়া'));
    ($this->as)()->postJson(($this->fac)('defect-types'), ['code' => 'shade', 'name' => ['en' => 'Shade variation', 'bn' => 'রঙের তারতম্য']])->assertCreated()->assertJsonPath('data.code', 'SHADE');
    ($this->as)()->postJson(($this->fac)('defect-types'), ['code' => 'SKIP', 'name' => ['en' => 'Again']])->assertUnprocessable()->assertJsonValidationErrors('code');
    ($this->as)($this->inspector)->postJson(($this->fac)('defect-types'), ['code' => 'X', 'name' => ['en' => 'Mine']])->assertForbidden();
    ($this->as)()->patchJson(($this->fac)("defect-types/{$this->types['SPOT']['id']}"), ['is_active' => false, 'base_version' => 1])->assertOk()->assertJsonPath('data.is_active', false);
    // A kind switched off takes no more counts.
    ($this->inspect)(['kind' => 'endline', 'line_id' => $this->line['id'], 'checked' => 10, 'defects' => ($this->defects)(['SPOT' => 1])])->assertNotFound();
});

it('counts end-line defects by kind and works out the DHU of a line and an order', function () {
    $check = ($this->inspect)(['kind' => 'endline', 'line_id' => $this->line['id'], 'hour' => 9, 'checked' => 120, 'defects' => ($this->defects)(['SKIP' => 4, 'OPEN' => 2]), 'op_id' => 'qc-1'])
        ->assertCreated()->json('data');
    // 6 defects in 120 pieces: 5 per hundred.
    expect($check)->toMatchArray(['kind' => 'endline', 'checked' => 120, 'defects' => 6, 'dhu_bp' => 500, 'result' => null])->and($check['by_type'])->toHaveCount(2);
    ($this->inspect)(['kind' => 'endline', 'line_id' => $this->line['id'], 'checked' => 120])->assertCreated();
    ($this->inspect)(['kind' => 'endline', 'line_id' => $this->line['id'], 'checked' => 1, 'op_id' => 'qc-1'])->assertOk()->assertJsonPath('data.id', $check['id']);
    ($this->inspect)(['kind' => 'endline', 'checked' => 10])->assertUnprocessable()->assertJsonValidationErrors('line_id');
    ($this->inspect)(['kind' => 'endline', 'line_id' => $this->line['id'], 'checked' => 10, 'lot_size' => 100])->assertUnprocessable()->assertJsonValidationErrors('lot_size');

    $quality = ($this->as)()->getJson(($this->fac)("orders/{$this->order['id']}/quality"))->assertOk()->json('data');
    expect($quality['endline'])->toBe(['checked' => 240, 'defects' => 6, 'dhu_bp' => 250, 'by_type' => [$this->types['SKIP']['id'] => 4, $this->types['OPEN']['id'] => 2]])
        ->and($quality['passed'])->toBeFalse();

    ($this->as)($this->inspector)->postJson(($this->fac)("inspections/{$check['id']}/void"), ['base_version' => 1, 'reason' => 'Wrong line'])->assertOk();
    expect(($this->as)()->getJson(($this->fac)("orders/{$this->order['id']}/quality"))->json('data.endline.dhu_bp'))->toBe(0);
    $day = ($this->as)($this->inspector)->getJson(($this->fac)('inspections'))->assertOk();
    expect($day->json('data'))->toHaveCount(2)->and($day->json('meta.aql'))->toBe('2.5')->and($day->json('meta.defect_types'))->toHaveCount(5);
});

it('samples a final inspection from the AQL table, and ships only after one passed', function () {
    // A lot of 1,000 at AQL 2.5: 80 pieces, at most 5 defects.
    expect(Quality::sample(1000, '2.5'))->toBe([80, 5])->and(Quality::sample(1000, '4.0'))->toBe([80, 7])
        ->and(Quality::sample(5, '2.5'))->toBe([2, 0])->and(Quality::sample(600000, '2.5'))->toBe([1250, 21]);
    $failed = ($this->inspect)(['kind' => 'final', 'lot_size' => 1000, 'defects' => ($this->defects)(['SKIP' => 4, 'MEASURE' => 2])])->assertCreated()->json('data');
    expect($failed)->toMatchArray(['kind' => 'final', 'checked' => 80, 'accept' => 5, 'defects' => 6, 'result' => 'fail', 'aql' => '2.5', 'line_id' => null]);
    expect(AuditLog::query()->where('action', 'factory_erp.final_inspected')->count())->toBe(1);

    $ship = fn () => ($this->as)()->postJson(($this->fac)("orders/{$this->order['id']}/ship"), ['base_version' => ($this->as)()->getJson(($this->fac)("orders/{$this->order['id']}"))->json('data.version')]);
    $version = ($this->as)()->getJson(($this->fac)("orders/{$this->order['id']}"))->json('data.version');
    ($this->as)()->postJson(($this->fac)("orders/{$this->order['id']}/ship"), ['base_version' => $version])->assertStatus(409)->assertJsonPath('code', 'final_not_passed');

    $passed = ($this->inspect)(['kind' => 'final', 'lot_size' => 1000, 'defects' => ($this->defects)(['SKIP' => 5])])->assertCreated()->json('data');
    expect($passed['result'])->toBe('pass');
    // A passed final voided: the last one counting failed again.
    ($this->as)($this->inspector)->postJson(($this->fac)("inspections/{$passed['id']}/void"), ['base_version' => 1, 'reason' => 'Sample from another order'])->assertOk();
    ($this->as)()->postJson(($this->fac)("orders/{$this->order['id']}/ship"), ['base_version' => $version])->assertStatus(409);

    orgRule($this->w->c1, 'factory_erp.final_aql', '4.0');
    expect(($this->inspect)(['kind' => 'final', 'lot_size' => 1000, 'defects' => ($this->defects)(['SKIP' => 7])])->assertCreated()->json('data'))->toMatchArray(['accept' => 7, 'result' => 'pass']);
    ($this->as)()->postJson(($this->fac)("orders/{$this->order['id']}/ship"), ['base_version' => $version])->assertOk()->assertJsonPath('data.status', 'shipped');
    // Shipped: no more inspections.
    ($this->inspect)(['kind' => 'final', 'lot_size' => 1000])->assertStatus(409);
});

it('shows today\'s defects on the home and rings the bell for a line with a high DHU', function () {
    ($this->inspect)(['kind' => 'endline', 'line_id' => $this->line['id'], 'checked' => 200, 'defects' => ($this->defects)(['SKIP' => 10])])->assertCreated();
    $widget = ($this->as)()->getJson('/api/modules/factory_erp/dashboard/quality')->assertOk()->json('data');
    expect($widget['value'])->toBe(10)->and($widget['hint'])->toContain('5.0');
    $bell = fn () => collect(($this->as)()->getJson('/api/attention')->assertOk()->json('data'))->pluck('key')->all();
    expect($bell())->toContain('factory_erp.dhu_high');
    orgRule($this->w->c1, 'factory_erp.dhu_warn_bp', 600);
    expect($bell())->not->toContain('factory_erp.dhu_high');
});

it('keeps QC to inspectors, the company, and the module being on', function () {
    $supervisor = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['factory_erp.view', 'factory_erp.produce'], 'Line supervisor')), $this->w->c1);
    ($this->inspect)(['kind' => 'endline', 'line_id' => $this->line['id'], 'checked' => 10], $supervisor)->assertForbidden();
    ($this->inspect)(['kind' => 'endline', 'line_id' => $this->line['id'], 'checked' => 10, 'result' => 'pass'])->assertUnprocessable();

    $otherToken = orgToken(createMember($this->w->c2), $this->w->c2);
    ($this->as)($otherToken)->getJson(($this->fac)("orders/{$this->order['id']}/quality", $this->w->c2))->assertNotFound();
    ($this->as)($otherToken)->postJson(($this->fac)('inspections', $this->w->c2), ['kind' => 'final', 'order_id' => $this->order['id'], 'lot_size' => 100])->assertNotFound();
    ($this->as)($otherToken)->patchJson(($this->fac)("defect-types/{$this->types['SKIP']['id']}", $this->w->c2), ['is_active' => false, 'base_version' => 1])->assertNotFound();

    toggles()->disable($this->w->c1, 'factory_erp', 'Test');
    ($this->inspect)(['kind' => 'final', 'lot_size' => 100])->assertForbidden();
});
