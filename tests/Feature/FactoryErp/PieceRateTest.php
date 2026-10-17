<?php

use App\Platform\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Modules\FactoryErp\Services\PieceRates;

/*
 * FAC-3c: operators' piece tickets (an operation's rate then, never more
 * pieces than cut, not one's own), paid through Payroll's EarningSource on
 * the slip, locked once the month's payroll is approved; HRM needed; who
 * may do what, other companies, the module off.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    foreach ([$this->w->c1, $this->w->c2] as $company) {
        $company->forceFill(['sector_key' => 'factory'])->save();
    }
    foreach (['inventory', 'accounting', 'crm', 'factory_erp', 'hrm', 'attendance', 'payroll'] as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);
    $this->fac = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/factory/{$path}";
    $this->pay = fn (string $path) => "/api/organizations/{$this->w->c1->id}/payroll/{$path}";
    $this->supervisorUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['factory_erp.view', 'factory_erp.produce'], 'Line supervisor'));
    $this->supervisor = orgToken($this->supervisorUser, $this->w->c1);

    $this->rahima = hireVia($this, $this->token, $this->w->b1, ['full_name' => 'Rahima Akter', 'joined_on' => '2026-10-01'])->assertCreated()->json('data');
    $this->karim = hireVia($this, $this->token, $this->w->b1, ['full_name' => 'Karim Uddin', 'joined_on' => '2026-10-01'])->assertCreated()->json('data');

    $buyer = ($this->as)()->postJson(($this->fac)('buyers'), ['name' => 'Nordic Wear AB'])->assertCreated()->json('data');
    $style = ($this->as)()->postJson(($this->fac)('styles'), ['buyer_id' => $buyer['id'], 'code' => 'NW-2611', 'name' => 'Basic polo', 'sizes' => ['M'], 'colors' => ['Navy']])->assertCreated()->json('data');
    // Rates 0.60 and 0.95 a piece; hemming has none yet.
    $saved = ($this->as)()->putJson(($this->fac)("styles/{$style['id']}/operations"), ['base_version' => $style['version'], 'lines' => [
        ['name' => 'Collar join', 'smv_milli' => 1200, 'piece_rate_minor' => 60],
        ['name' => 'Side seam', 'smv_milli' => 1800, 'piece_rate_minor' => 95],
        ['name' => 'Hem', 'smv_milli' => 900],
    ]])->assertOk()->json('data');
    $this->ops = collect($saved['operations'])->keyBy('name');
    $this->order = ($this->as)()->postJson(($this->fac)('orders'), ['style_id' => $style['id'], 'ship_on' => '2026-12-15', 'lines' => [['size' => 'M', 'color' => 'Navy', 'quantity' => 1000]]])
        ->assertCreated()->json('data');
    ($this->as)()->postJson(($this->fac)("orders/{$this->order['id']}/confirm"), ['base_version' => 1])->assertOk();
    ($this->as)()->postJson(($this->fac)("orders/{$this->order['id']}/cuttings"), ['lines' => [['size' => 'M', 'color' => 'Navy', 'quantity' => 1000]]])->assertCreated();
    $this->ticket = fn (array $employee, string $operation, int $pieces, array $extra = [], ?string $token = null) => ($this->as)($token ?? $this->supervisor)->postJson(($this->fac)('piece-tickets'), [
        'employee_id' => $employee['id'], 'order_id' => $this->order['id'], 'operation_id' => $this->ops[$operation]['id'], 'pieces' => $pieces, 'worked_on' => '2026-10-30', ...$extra,
    ]);
});

it('writes an operator\'s pieces at the operation\'s rate then, never more than was cut, once per tap', function () {
    $ticket = ($this->ticket)($this->rahima, 'Collar join', 300, ['op_id' => 't-1'])->assertCreated()->json('data');
    expect($ticket)->toMatchArray(['pieces' => 300, 'rate_minor' => 60, 'rate_per' => 1, 'amount_minor' => 18000, 'operation_name' => 'Collar join']);
    ($this->ticket)($this->rahima, 'Collar join', 300, ['op_id' => 't-1'])->assertOk()->assertJsonPath('data.id', $ticket['id']);
    expect(AuditLog::query()->where('action', 'factory_erp.piece_ticket_written')->count())->toBe(1);

    // 1,000 cut: 700 more collars at most, by anyone.
    ($this->ticket)($this->karim, 'Collar join', 701)->assertUnprocessable()->assertJsonPath('code', 'over_operation')->assertJsonPath('left', 700);
    ($this->ticket)($this->karim, 'Collar join', 700)->assertCreated();
    ($this->ticket)($this->karim, 'Hem', 10)->assertUnprocessable()->assertJsonPath('code', 'no_rate');
    ($this->ticket)($this->karim, 'Side seam', 10, ['worked_on' => '2026-11-03'])->assertUnprocessable()->assertJsonPath('code', 'future_date');
    // Before joining: not working here then.
    ($this->ticket)($this->karim, 'Side seam', 10, ['worked_on' => '2026-09-30'])->assertNotFound()->assertJsonPath('code', 'employee_not_found');

    // A later rate change leaves the ticket as written; per dozen, half up.
    expect(PieceRates::amount(25, 95, 12))->toBe(198)->and(PieceRates::amount(300, 60, 1))->toBe(18000);
    orgRule($this->w->c1, 'factory_erp.piece_rate_per', 'dozen');
    expect(($this->ticket)($this->rahima, 'Side seam', 25)->assertCreated()->json('data'))->toMatchArray(['rate_per' => 12, 'amount_minor' => 198]);

    $day = ($this->as)($this->supervisor)->getJson(($this->fac)('piece-tickets?date=2026-10-30'))->assertOk();
    expect($day->json('data'))->toHaveCount(3)
        ->and($day->json("meta.month.{$this->rahima['id']}"))->toBe(['pieces' => 325, 'amount' => 18198])
        ->and(collect($day->json('meta.employees'))->pluck('name')->all())->toContain('Rahima Akter');
});

it('keeps people from writing their own tickets unless the company allows it', function () {
    ($this->as)()->putJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$this->rahima['id']}/login", ['base_version' => $this->rahima['version'], 'user_id' => $this->supervisorUser->id])->assertOk();
    ($this->ticket)($this->rahima, 'Collar join', 10)->assertForbidden()->assertJsonPath('code', 'own_ticket');
    orgRule($this->w->c1, 'factory_erp.piece_ticket_self', true);
    ($this->ticket)($this->rahima, 'Collar join', 10)->assertCreated();
});

it('pays piece rates on the payroll slip, and locks the month once the payroll is approved', function () {
    // The company keeps books: an approved payroll posts there.
    setUpBooks($this, $this->token, $this->w->c1, ['template' => 'factory'])->assertCreated();
    $runner = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'payroll.view', 'payroll.run'], 'Payroll clerk')), $this->w->c1);
    $approver = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['payroll.view', 'payroll.approve'], 'Approver')), $this->w->c1);
    $structure = ($this->as)($runner)->postJson(($this->pay)('structures'), ['code' => 'OPS', 'name' => ['en' => 'Operators'], 'items' => []])->assertCreated()->json('data');
    foreach ([$this->rahima, $this->karim] as $employee) {
        ($this->as)($runner)->postJson(($this->pay)("employees/{$employee['id']}/salary"), ['structure_id' => $structure['id'], 'basic_minor' => 1250000, 'effective_from' => '2026-10-01'])->assertCreated();
    }

    $kept = ($this->ticket)($this->rahima, 'Collar join', 300)->assertCreated()->json('data');
    $wrong = ($this->ticket)($this->rahima, 'Side seam', 200)->assertCreated()->json('data');
    ($this->as)($this->supervisor)->postJson(($this->fac)("piece-tickets/{$wrong['id']}/void"), ['base_version' => 1, 'reason' => 'Karim did these'])->assertOk();
    ($this->ticket)($this->rahima, 'Side seam', 100)->assertCreated();

    $run = ($this->as)($runner)->postJson(($this->pay)('runs'), ['period' => '2026-10'])->assertCreated()->json('data');
    $run = ($this->as)($runner)->postJson(($this->pay)("runs/{$run['id']}/calculate"), ['base_version' => $run['version']])->assertOk()->json('data');
    $slip = collect(($this->as)($runner)->getJson(($this->pay)("runs/{$run['id']}"))->json('data.slips'))->firstWhere('employee_name', 'Rahima Akter');
    $lines = collect(($this->as)($runner)->getJson(($this->pay)("runs/{$run['id']}/slips/{$slip['id']}"))->assertOk()->json('data.lines'))->keyBy('code');
    // 300 × 0.60 + 100 × 0.95 = 275.00; the voided ticket pays nothing.
    expect($lines['PIECE_RATE'])->toMatchArray(['kind' => 'earning', 'amount_minor' => 27500, 'taxable' => true]);

    $run = ($this->as)($runner)->postJson(($this->pay)("runs/{$run['id']}/submit"), ['base_version' => $run['version']])->assertOk()->json('data');
    ($this->as)($approver)->postJson(($this->pay)("runs/{$run['id']}/approve"), ['base_version' => $run['version']])->assertOk()->assertJsonPath('data.status', 'approved');

    // October is paid: its tickets stay as they are.
    ($this->ticket)($this->rahima, 'Collar join', 10)->assertStatus(409)->assertJsonPath('code', 'month_locked');
    ($this->as)($this->supervisor)->postJson(($this->fac)("piece-tickets/{$kept['id']}/void"), ['base_version' => 1, 'reason' => 'Too late'])->assertStatus(409)->assertJsonPath('code', 'month_locked');
    ($this->ticket)($this->rahima, 'Collar join', 10, ['worked_on' => '2026-11-01'])->assertCreated();

    // Factory ERP off: it adds nothing to pay.
    $company = $this->w->c1->fresh();
    toggles()->disable($company, 'factory_erp', 'Test');
    expect(app(PieceRates::class)->earnings($company, [$this->rahima['id']], CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30')))->toBe([]);
});

it('needs HRM, and keeps tickets to supervisors, the company and the module being on', function () {
    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['factory_erp.view'], 'Viewer')), $this->w->c1);
    ($this->ticket)($this->rahima, 'Collar join', 10, token: $viewer)->assertForbidden();
    // A viewer sees tickets but not operators' names or pay.
    expect(($this->as)($viewer)->getJson(($this->fac)('piece-tickets'))->assertOk()->json('meta.employees'))->toBe([]);
    ($this->ticket)($this->rahima, 'Collar join', 10, ['amount_minor' => 1])->assertUnprocessable();

    $other = hireVia($this, orgToken(createMember($this->w->c2), $this->w->c2), $this->w->c2, ['full_name' => 'Other Person'])->json('data');
    if ($other !== null) {
        ($this->ticket)($other, 'Collar join', 10)->assertNotFound();
    }
    $otherToken = orgToken(createMember($this->w->c2), $this->w->c2);
    ($this->as)($otherToken)->postJson(($this->fac)('piece-tickets', $this->w->c2), ['employee_id' => $this->rahima['id'], 'order_id' => $this->order['id'], 'operation_id' => $this->ops['Collar join']['id'], 'pieces' => 1])
        ->assertNotFound();

    // HRM off (and Payroll and Attendance with it): no operators to pay.
    toggles()->disable($this->w->c1, 'hrm', 'Test', confirm: true);
    ($this->ticket)($this->rahima, 'Collar join', 10)->assertStatus(409)->assertJsonPath('code', 'needs_hrm');
    toggles()->disable($this->w->c1, 'factory_erp', 'Test');
    ($this->ticket)($this->rahima, 'Collar join', 10)->assertForbidden();
});
