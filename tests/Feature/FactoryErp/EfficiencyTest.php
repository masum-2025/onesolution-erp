<?php

use App\Platform\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Modules\FactoryErp\Services\Efficiency;

/*
 * FAC-3a: a style's operation bulletin (standard minutes, piece rates set
 * only by managers, audited), who worked on a line on a day, and the
 * line's efficiency and hourly target from them; the dashboard; who may
 * do what, other companies.
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
    $this->merchandiser = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['factory_erp.view', 'factory_erp.merchandise'], 'Merchandiser')), $this->w->c1);
    $this->supervisor = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['factory_erp.view', 'factory_erp.produce'], 'Line supervisor')), $this->w->c1);

    $buyer = ($this->as)()->postJson(($this->fac)('buyers'), ['name' => 'Nordic Wear AB'])->assertCreated()->json('data');
    $this->style = ($this->as)()->postJson(($this->fac)('styles'), ['buyer_id' => $buyer['id'], 'code' => 'NW-2611', 'name' => 'Basic polo', 'sizes' => ['M'], 'colors' => ['Navy']])
        ->assertCreated()->json('data');
    $this->order = ($this->as)()->postJson(($this->fac)('orders'), ['style_id' => $this->style['id'], 'ship_on' => '2026-12-15', 'lines' => [['size' => 'M', 'color' => 'Navy', 'quantity' => 1000]]])
        ->assertCreated()->json('data');
    ($this->as)()->postJson(($this->fac)("orders/{$this->order['id']}/confirm"), ['base_version' => 1])->assertOk();
    ($this->as)()->postJson(($this->fac)("orders/{$this->order['id']}/cuttings"), ['lines' => [['size' => 'M', 'color' => 'Navy', 'quantity' => 1000]]])->assertCreated();
    $this->line = ($this->as)()->postJson(($this->fac)('lines'), ['code' => 'L-01', 'name' => 'Line 1', 'machines' => 24])->assertCreated()->json('data');
    $this->operations = function (array $lines, ?string $token = null, ?int $version = null) {
        $version ??= ($this->as)()->getJson(($this->fac)("styles/{$this->style['id']}"))->json('data.version');

        return ($this->as)($token ?? $this->merchandiser)->putJson(($this->fac)("styles/{$this->style['id']}/operations"), ['base_version' => $version, 'lines' => $lines]);
    };
});

it('keeps a style\'s operation bulletin: standard minutes add up, piece rates only from managers, audited', function () {
    $saved = ($this->operations)([
        ['name' => 'Collar join', 'machine' => 'SNLS', 'smv_milli' => 1200],
        ['name' => 'Sleeve attach', 'machine' => 'Overlock', 'smv_milli' => 1450],
        ['name' => 'Side seam', 'machine' => 'Overlock', 'smv_milli' => 9850],
    ])->assertOk()->json('data');
    expect($saved['smv_milli'])->toBe(12500)->and($saved['operations'])->toHaveCount(3)->and($saved['operations'][0]['name'])->toBe('Collar join');

    // A piece rate is pay: a merchandiser cannot set it, a manager can.
    $withRate = [['name' => 'Collar join', 'smv_milli' => 1200, 'piece_rate_minor' => 60], ['name' => 'Sleeve attach', 'smv_milli' => 1450], ['name' => 'Side seam', 'smv_milli' => 9850]];
    ($this->operations)($withRate)->assertForbidden();
    ($this->operations)($withRate, $this->token)->assertOk()->assertJsonPath('data.operations.0.piece_rate_minor', 60);
    // Unchanged rates need no manager.
    ($this->operations)([...$withRate, ['name' => 'Hem', 'smv_milli' => 800]])->assertOk()->assertJsonPath('data.smv_milli', 13300);
    ($this->operations)([['name' => 'Hem', 'smv_milli' => 1], ['name' => 'hem ', 'smv_milli' => 2]])->assertUnprocessable()->assertJsonValidationErrors('lines');
    ($this->operations)([['name' => 'Hem', 'smv_milli' => 1]], version: 1)->assertStatus(409);

    $log = AuditLog::query()->where('action', 'factory_erp.operations_saved')->latest('id')->first();
    expect(collect($log->new_values['operations'])->firstWhere('name', 'Collar join')['piece_rate_minor'])->toBe(60);
});

it('works out a line\'s efficiency and hourly target from its day and what it sewed', function () {
    ($this->operations)([['name' => 'All', 'smv_milli' => 12500]])->assertOk();
    $day = ($this->as)($this->supervisor)->postJson(($this->fac)('line-days'), ['line_id' => $this->line['id'], 'operators' => 20, 'helpers' => 4])->assertCreated()->json('data');
    // The company's shift when no minutes are written.
    expect($day)->toMatchArray(['operators' => 20, 'helpers' => 4, 'minutes' => 480, 'version' => 1, 'worked_on' => '2026-11-02']);
    ($this->as)($this->supervisor)->postJson(($this->fac)('outputs'), ['line_id' => $this->line['id'], 'order_id' => $this->order['id'], 'hour' => 9, 'lines' => [['size' => 'M', 'color' => 'Navy', 'good' => 300]]])->assertCreated();

    // 300 × 12.5 = 3,750 earned minutes over 20 × 480 = 9,600: 39.06%.
    $meta = ($this->as)($this->supervisor)->getJson(($this->fac)('outputs'))->assertOk()->json('meta');
    expect($meta['efficiency'][$this->line['id']])->toBe(['good' => 300, 'earned_milli' => 3750000, 'available' => 9600, 'people' => 20, 'efficiency_bp' => 3906])
        ->and($meta['orders'][0]['smv_milli'])->toBe(12500)->and($meta['days'][0]['operators'])->toBe(20);
    // 20 people × 60 ÷ 12.5 × 60% = 57.6: 57 an hour.
    expect(Efficiency::hourTarget(20, 12500, 6000))->toBe(57)->and(Efficiency::efficiencyBp(0, 0))->toBeNull();

    // Changed with its version; helpers count where the company says so.
    ($this->as)($this->supervisor)->postJson(($this->fac)('line-days'), ['line_id' => $this->line['id'], 'operators' => 22])->assertStatus(409);
    ($this->as)($this->supervisor)->postJson(($this->fac)('line-days'), ['line_id' => $this->line['id'], 'operators' => 20, 'helpers' => 4, 'minutes' => 480, 'base_version' => 1])->assertOk();
    orgRule($this->w->c1, 'factory_erp.efficiency_counts_helpers', true);
    expect(($this->as)()->getJson(($this->fac)('outputs'))->json("meta.efficiency.{$this->line['id']}.efficiency_bp"))->toBe(intdiv(3750000 * 10, 24 * 480));

    expect(($this->as)()->getJson('/api/modules/factory_erp/dashboard/efficiency')->assertOk()->json('data.items.0'))->toMatchArray(['label' => 'L-01', 'value' => 32]);
    ($this->as)($this->supervisor)->postJson(($this->fac)('line-days'), ['line_id' => $this->line['id'], 'operators' => 1, 'worked_on' => '2026-11-03'])->assertUnprocessable()->assertJsonPath('code', 'future_date');
});

it('lets only line people write a line\'s day, never across companies', function () {
    ($this->as)($this->merchandiser)->postJson(($this->fac)('line-days'), ['line_id' => $this->line['id'], 'operators' => 20])->assertForbidden();
    ($this->as)($this->supervisor)->postJson(($this->fac)('line-days'), ['line_id' => $this->line['id'], 'operators' => 20, 'efficiency' => 9000])->assertUnprocessable();

    $otherToken = orgToken(createMember($this->w->c2), $this->w->c2);
    ($this->as)($otherToken)->postJson(($this->fac)('line-days', $this->w->c2), ['line_id' => $this->line['id'], 'operators' => 20])->assertUnprocessable()->assertJsonPath('code', 'line_off');
    ($this->as)($otherToken)->putJson(($this->fac)("styles/{$this->style['id']}/operations", $this->w->c2), ['base_version' => 1, 'lines' => []])->assertNotFound();

    toggles()->disable($this->w->c1, 'factory_erp', 'Test');
    ($this->as)($this->supervisor)->postJson(($this->fac)('line-days'), ['line_id' => $this->line['id'], 'operators' => 20])->assertForbidden();
});
