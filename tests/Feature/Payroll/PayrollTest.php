<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Payroll\Export\PayrollExporter;
use Modules\Payroll\Services\PayCalculator;

/*
 * PAY-1: pay make-up, salaries, the monthly run (days and over time from
 * Attendance, prorating, adjustments, tax), approval by other people at
 * each level, posting to the books where the company keeps them, the
 * employee's own payslips, and isolation. Company C1 is in Asia/Dhaka.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2] as $group) {
        toggles()->enable($group, 'attendance', 'Test setup');
        toggles()->enable($group, 'payroll', 'Test setup');
    }
    $this->runner = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'payroll.view', 'payroll.run', 'attendance.view', 'attendance.manage'], 'Payroll clerk')), $this->w->c1);
    $this->approver = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['payroll.view', 'payroll.approve'], 'Approver')), $this->w->c1);
    $this->approver2 = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['payroll.view', 'payroll.approve'], 'Director')), $this->w->c1);
    $this->workerUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['attendance.punch'], 'Worker'));
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/payroll/{$path}";
    $att = fn (string $path) => "/api/organizations/{$this->w->c1->id}/attendance/{$path}";

    $hire = fn (string $name, string $joined) => hireVia($this, $this->w->token, $this->w->b1, ['full_name' => $name, 'joined_on' => $joined])->assertCreated()->json('data');
    $this->rahima = $hire('Rahima Akter', '2026-10-01');
    $this->karim = $hire('Karim Uddin', '2026-10-16');
    $this->asToken($this->w->token)->putJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$this->rahima['id']}/login", ['base_version' => $this->rahima['version'], 'user_id' => $this->workerUser->id])->assertOk();

    // Pay make-up: house rent 50% of basic, medical 1,500.00, provident fund 5% of basic.
    $component = fn (array $data) => $this->asToken($this->runner)->postJson(($this->api)('components'), $data)->assertCreated()->json('data');
    $house = $component(['code' => 'HOUSE', 'name' => ['en' => 'House rent', 'bn' => 'বাড়িভাড়া'], 'kind' => 'earning']);
    $medical = $component(['code' => 'MEDICAL', 'name' => ['en' => 'Medical'], 'kind' => 'earning', 'taxable' => false]);
    $pf = $component(['code' => 'PF', 'name' => ['en' => 'Provident fund'], 'kind' => 'deduction']);
    $this->structure = $this->asToken($this->runner)->postJson(($this->api)('structures'), ['code' => 'STAFF', 'name' => ['en' => 'Staff'], 'items' => [
        ['component_id' => $house['id'], 'calc' => 'percent_of_basic', 'rate_bp' => 5000],
        ['component_id' => $medical['id'], 'calc' => 'fixed', 'amount_minor' => 150000],
        ['component_id' => $pf['id'], 'calc' => 'percent_of_basic', 'rate_bp' => 500],
    ]])->assertCreated()->json('data');
    $this->salary = fn (array $employee, int $basic, string $from) => $this->asToken($this->runner)->postJson(($this->api)("employees/{$employee['id']}/salary"), [
        'structure_id' => $this->structure['id'], 'basic_minor' => $basic, 'effective_from' => $from,
    ]);
    ($this->salary)($this->rahima, 3000000, '2026-10-01')->assertCreated();
    ($this->salary)($this->karim, 2000000, '2026-10-16')->assertCreated();

    // Attendance: Rahima two long days at the end of the month (two hours over), Karim absent on the 30th.
    $shift = $this->asToken($this->runner)->postJson($att('shifts'), ['code' => 'DAY', 'name' => ['en' => 'Day'], 'start_minute' => 540, 'end_minute' => 1020, 'break_minutes' => 60])->json('data');
    $this->asToken($this->runner)->postJson($att('rosters'), ['employee_ids' => [$this->rahima['id'], $this->karim['id']], 'shift_id' => $shift['id'], 'from' => '2026-10-28'])->assertCreated();
    foreach (['2026-10-28T09:00', '2026-10-28T19:00'] as $at) {
        $this->asToken($this->runner)->postJson($att('punches'), ['employee_id' => $this->rahima['id'], 'at' => $at, 'note' => 'Register'])->assertCreated();
    }
    foreach (['2026-10-28T09:00', '2026-10-28T17:00'] as $at) {
        $this->asToken($this->runner)->postJson($att('punches'), ['employee_id' => $this->karim['id'], 'at' => $at, 'note' => 'Register'])->assertCreated();
    }
    $this->run = fn () => $this->asToken($this->runner)->postJson(($this->api)('runs'), ['period' => '2026-10'])->assertCreated()->json('data');
    $this->step = fn (array $run, string $step, ?string $token = null, array $data = []) => $this->asToken($token ?? $this->runner)
        ->postJson(($this->api)("runs/{$run['id']}/{$step}"), ['base_version' => $run['version'], ...$data]);
    $this->slips = fn (array $run) => collect($this->asToken($this->runner)->getJson(($this->api)("runs/{$run['id']}"))->assertOk()->json('data.slips'))->keyBy('employee_name');
});

it('works out a month: full and part months, absences, over time and deductions', function () {
    $run = ($this->run)();
    $this->asToken($this->runner)->postJson(($this->api)('runs'), ['period' => '2026-10'])->assertConflict()->assertJsonPath('code', 'run_exists');
    $run = ($this->step)($run, 'calculate')->assertOk()->json('data');
    $slips = ($this->slips)($run);

    // 10-29..10-31: Thu/Fri absent for both (no punches), Sat/Sun weekend; Rahima 28th: 600 - 60 = 540 worked, 120 over.
    expect($slips['Rahima Akter'])->toMatchArray([
        'period_days' => 31, 'employed_days' => 31, 'absent_days' => 2, 'overtime_minutes' => 120, 'basic_minor' => 3000000,
    ])->and($slips['Karim Uddin'])->toMatchArray(['employed_days' => 16, 'absent_days' => 2]);

    // Rahima: 58 of 62 half-days paid; over time 3,000,000 x 120 x 1.5 / (208 x 60) = 43,269.
    $rahima = $this->asToken($this->runner)->getJson(($this->api)("runs/{$run['id']}/slips/{$slips['Rahima Akter']['id']}"))->assertOk()->json('data');
    $lines = collect($rahima['lines'])->keyBy('code');
    expect($lines['BASIC']['amount_minor'])->toBe(2806452)
        ->and($lines['HOUSE']['amount_minor'])->toBe(1403226)
        ->and($lines['MEDICAL']['amount_minor'])->toBe(140323)
        ->and($lines['OVERTIME']['amount_minor'])->toBe(43269)
        ->and($lines['PF'])->toMatchArray(['kind' => 'deduction', 'amount_minor' => 150000])
        ->and($rahima)->toMatchArray(['earnings_minor' => 4393270, 'deductions_minor' => 150000, 'tax_minor' => 0, 'net_minor' => 4243270]);

    // Karim: 16 days employed, 2 absent: 28 of 62 half-days.
    expect($slips['Karim Uddin'])->toMatchArray(['earnings_minor' => 903226 + 451613 + 67742, 'deductions_minor' => 100000])
        ->and($run)->toMatchArray(['employees' => 2, 'net_minor' => 4243270 + 1322581]);
});

it('needs a calculation without problems before it is sent, and adjustments count', function () {
    hireVia($this, $this->w->token, $this->w->b1, ['full_name' => 'No Salary', 'joined_on' => '2026-10-01'])->assertCreated();
    $run = ($this->step)(($this->run)(), 'submit')->assertConflict()->json();
    expect($run['code'])->toBe('not_calculated');

    $run = ($this->step)(($this->asToken($this->runner)->getJson(($this->api)('runs'))->json('data.0')), 'calculate')->assertOk()->json('data');
    ($this->step)($run, 'submit')->assertConflict()->assertJsonPath('code', 'slip_problems');
    expect(($this->slips)($run)['No Salary']['problem'])->toBe('no_salary');

    $none = collect(($this->slips)($run))->firstWhere('problem', 'no_salary');
    ($this->salary)(['id' => $none['employee_id']], 1000000, '2026-10-01')->assertCreated();
    $this->asToken($this->runner)->postJson(($this->api)("runs/{$run['id']}/adjustments"), ['employee_id' => $this->rahima['id'], 'kind' => 'earning', 'label' => 'Puja bonus', 'amount_minor' => 500000])->assertCreated();
    $run = $this->asToken($this->runner)->getJson(($this->api)("runs/{$run['id']}"))->json('data');
    expect($run['calculated_at'])->toBeNull();
    $run = ($this->step)($run, 'calculate')->assertOk()->json('data');
    expect(($this->slips)($run)['Rahima Akter']['earnings_minor'])->toBe(4393270 + 500000);
    ($this->step)($run, 'submit')->assertOk()->assertJsonPath('data.status', 'pending_approval');
});

it('is approved by other people at each level, posted to the books and paid', function () {
    toggles()->enable($this->w->g1, 'accounting', 'Test setup');
    $books = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['accounting.view', 'accounting.manage'], 'Books')), $this->w->c1);
    setUpBooks($this, $books, $this->w->c1)->assertCreated();
    trustedOrgRule($this->w->c1, 'payroll.salary_approval_levels', 2);

    $run = ($this->step)(($this->run)(), 'calculate')->json('data');
    $run = ($this->step)($run, 'submit')->assertOk()->json('data');
    ($this->step)($run, 'approve')->assertForbidden();
    $run = ($this->step)($run, 'approve', $this->approver)->assertOk()->json('data');
    expect($run)->toMatchArray(['status' => 'pending_approval', 'approvals' => 1, 'approvals_needed' => 2]);
    ($this->step)($run, 'approve', $this->approver)->assertForbidden()->assertJsonPath('code', 'already_approved');
    $run = ($this->step)($run, 'approve', $this->approver2)->assertOk()->json('data');
    expect($run['status'])->toBe('approved')->and($run['journal_id'])->not->toBeNull();

    $row = fn (string $code) => collect($this->asToken($books)->getJson("/api/organizations/{$this->w->c1->id}/accounting/reports/trial-balance?as_of=2026-11-02")->json('data.rows'))->firstWhere('code', $code);
    expect($row('5200')['debit_minor'])->toBe(4393270 + 1422581)
        ->and($row('2120')['credit_minor'])->toBe(4243270 + 1322581)
        ->and($row('2140')['credit_minor'])->toBe(250000);

    ($this->step)($run, 'calculate')->assertConflict()->assertJsonPath('code', 'not_draft');
    $paid = ($this->step)($run, 'pay', null, ['paid_on' => '2026-11-02'])->assertOk()->json('data');
    expect($paid)->toMatchArray(['status' => 'paid', 'paid_on' => '2026-11-02'])
        ->and($row('2120'))->toBeNull()
        ->and($row('1120')['credit_minor'])->toBe(4243270 + 1322581)
        ->and(AuditLog::query()->where('action', 'payroll.run_approved')->count())->toBe(1);
});

it('goes back to a draft when rejected, and the employee sees only approved slips of their own', function () {
    $worker = orgToken($this->workerUser, $this->w->c1);
    $run = ($this->step)(($this->step)(($this->run)(), 'calculate')->json('data'), 'submit')->json('data');
    expect($this->asToken($worker)->getJson(($this->api)('me/slips'))->assertOk()->json('data'))->toBe([]);

    ($this->step)($run, 'reject', $this->approver)->assertUnprocessable()->assertJsonValidationErrors('reason');
    $run = ($this->step)($run, 'reject', $this->approver, ['reason' => 'Bonus missing'])->assertOk()->json('data');
    expect($run)->toMatchArray(['status' => 'draft', 'reject_reason' => 'Bonus missing']);

    $run = ($this->step)($run, 'submit')->assertOk()->json('data');
    ($this->step)($run, 'approve', $this->approver)->assertOk();
    $mine = $this->asToken($worker)->getJson(($this->api)('me/slips'))->assertOk()->json('data');
    expect($mine)->toHaveCount(1)->and($mine[0])->toMatchArray(['employee_name' => 'Rahima Akter', 'period' => '2026-10', 'net_minor' => 4243270]);
    $slip = $this->asToken($worker)->getJson(($this->api)("me/slips/{$mine[0]['id']}"))->assertOk()->json('data');
    expect(collect($slip['lines'])->pluck('code')->all())->toContain('BASIC', 'OVERTIME', 'PF');

    $karimSlip = ($this->slips)($run)['Karim Uddin'];
    $this->asToken($worker)->getJson(($this->api)("me/slips/{$karimSlip['id']}"))->assertNotFound();
});

it('keeps pay to the company and to people with the right, account numbers masked', function () {
    $this->asToken($this->runner)->putJson(($this->api)("employees/{$this->rahima['id']}/payment"), ['method' => 'bank', 'provider' => 'Dutch-Bangla Bank', 'account_number' => '1234-5678-9012'])->assertOk()
        ->assertJsonPath('data.account_number', '••••9012');
    $this->asToken($this->runner)->putJson(($this->api)("employees/{$this->karim['id']}/payment"), ['method' => 'mobile'])->assertUnprocessable()->assertJsonValidationErrors('account_number');
    expect(json_encode(AuditLog::query()->where('action', 'payroll.payment_details_set')->sole()->new_values))->not->toContain('5678');

    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view'], 'HR viewer')), $this->w->c1);
    $this->asToken($viewer)->getJson(($this->api)("employees/{$this->rahima['id']}"))->assertForbidden();
    $this->asToken($viewer)->getJson(($this->api)('runs'))->assertForbidden();
    ($this->salary)($this->rahima, 100, '2026-09-01')->assertUnprocessable()->assertJsonValidationErrors('effective_from');

    $c2 = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['payroll.view', 'payroll.run'], 'C2 payroll')), $this->w->c2);
    $run = ($this->run)();
    $this->asToken($c2)->getJson(($this->api)("runs/{$run['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($c2)->getJson(($this->api)("employees/{$this->rahima['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($c2)->getJson(($this->api)('components', $this->w->c2))->assertOk()->assertJsonPath('data', []);

    toggles()->disable($this->w->g1, 'payroll', 'Test setup', confirm: true);
    $this->asToken($this->runner)->getJson(($this->api)('runs'))->assertForbidden();
});

it('does tax, rates and rounding with integers', function () {
    // Yearly: first 350,000.00 free, next 100,000.00 at 5%, the rest at 10%.
    $slabs = [[35000000, 0], [45000000, 500], [null, 1000]];
    expect(PayCalculator::monthlyTax(5000000, $slabs))->toBe(166667)
        ->and(PayCalculator::monthlyTax(2000000, $slabs))->toBe(0)
        ->and(PayCalculator::toBasisPoints('1.5'))->toBe(15000)
        ->and(PayCalculator::toBasisPoints('7.5', 100))->toBe(750)
        ->and(PayCalculator::toBasisPoints('x'))->toBeNull()
        ->and(PayCalculator::share(3333, 5000))->toBe(1667);

    $slip = PayCalculator::slip(
        ['basic' => 1000, 'items' => [], 'period_days' => 30, 'employed_days' => 30, 'absent_days' => 0, 'half_days' => 0, 'overtime_minutes' => 0, 'late_minutes' => 0,
            'adjustments' => [['kind' => 'deduction', 'label' => 'Advance', 'amount' => 5000, 'taxable' => false]]],
        ['deduct_absence' => true, 'overtime_multiplier_bp' => 15000, 'overtime_base' => 'basic', 'monthly_hours' => 208, 'late_deduction' => false, 'tax_slabs' => []],
    );
    expect($slip)->toMatchArray(['net' => -4000, 'problem' => 'negative_net']);
});

it('hands payroll to the client\'s data export', function () {
    ($this->step)(($this->run)(), 'calculate')->assertOk();
    app(CurrentContext::class)->clear();
    $datasets = app(PayrollExporter::class)->export($this->w->c1, Organization::query()->subtreeOf($this->w->c1)->pluck('id')->all());

    expect(array_keys($datasets))->toBe(['components', 'structures', 'structure_items', 'salaries', 'payment_details', 'runs', 'run_approvals', 'slips', 'slip_lines', 'adjustments', 'loans', 'loan_installments', 'bonus_runs', 'bonus_lines', 'pf_entries', 'settlements', 'settlement_lines'])
        ->and(iterator_to_array($datasets['slips'], false))->toHaveCount(2);
});
