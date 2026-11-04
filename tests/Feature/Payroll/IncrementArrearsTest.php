<?php

use App\Platform\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Modules\Payroll\Letters\IncrementLetters;
use Modules\Payroll\Letters\PayLetterFields;
use Modules\Payroll\Models\IncrementArrear;
use Modules\Payroll\Models\Slip;
use Modules\Payroll\Services\PayCalculator;

/*
 * PAY-5: increment arrears. A round may start in a month already paid
 * (rule payroll.increment_arrears, payroll.increment_arrears_max_months
 * back). Each month's arrears come from its own payslip's facts at the old
 * and the new basic (structure earnings only), shown before approval,
 * planned when applied, paid with the next run (one line, taxed as a
 * one-off payment, provident fund on its basic part by payroll.arrears_pf),
 * released when that run is deleted. A month waiting for approval stops
 * the round being applied.
 */

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2027-03-05 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2] as $group) {
        setPlan($group, 'business');
        toggles()->enable($group, 'attendance', 'Test setup');
        toggles()->enable($group, 'payroll', 'Test setup');
    }
    trustedOrgRule($this->w->c1, 'payroll.salary_approval_levels', 1);
    trustedOrgRule($this->w->c1, 'payroll.pf_enabled', true);
    $this->runner = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'payroll.view', 'payroll.run'], 'Payroll clerk')), $this->w->c1);
    $this->approver = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['payroll.view', 'payroll.approve'], 'Approver')), $this->w->c1);
    $this->api = fn (string $path) => "/api/organizations/{$this->w->c1->id}/payroll/{$path}";

    $hire = fn (string $name) => hireVia($this, $this->w->token, $this->w->b1, ['full_name' => $name, 'joined_on' => '2026-06-01'])->assertCreated()->json('data');
    $this->rahima = $hire('Rahima Akter');
    $this->selim = $hire('Selim Reza');
    // House rent 50% of the basic and a fixed medical allowance, both earnings (prorated).
    $component = fn (array $data) => $this->asToken($this->runner)->postJson(($this->api)('components'), $data)->assertCreated()->json('data');
    $house = $component(['code' => 'HOUSE', 'name' => ['en' => 'House rent'], 'kind' => 'earning']);
    $medical = $component(['code' => 'MEDICAL', 'name' => ['en' => 'Medical'], 'kind' => 'earning', 'taxable' => false]);
    $structure = $this->asToken($this->runner)->postJson(($this->api)('structures'), ['code' => 'STAFF', 'name' => ['en' => 'Staff'], 'items' => [
        ['component_id' => $house['id'], 'calc' => 'percent_of_basic', 'rate_bp' => 5000],
        ['component_id' => $medical['id'], 'calc' => 'fixed', 'amount_minor' => 150000],
    ]])->assertCreated()->json('data');
    foreach ([[$this->rahima, 3000000], [$this->selim, 4000000]] as [$employee, $basic]) {
        $this->asToken($this->runner)->postJson(($this->api)("employees/{$employee['id']}/salary"), ['structure_id' => $structure['id'], 'basic_minor' => $basic, 'effective_from' => '2026-06-01'])->assertCreated();
    }

    $this->runStep = fn (array $run, string $step, ?string $token = null, array $data = []) => $this->asToken($token ?? $this->runner)
        ->postJson(($this->api)("runs/{$run['id']}/{$step}"), ['base_version' => $run['version'], ...$data]);
    $this->month = function (string $period, bool $approve = true) {
        $run = $this->asToken($this->runner)->postJson(($this->api)('runs'), ['period' => $period])->assertCreated()->json('data');
        $run = ($this->runStep)($run, 'calculate')->assertOk()->json('data');
        $run = ($this->runStep)($run, 'submit')->assertOk()->json('data');

        return $approve ? ($this->runStep)($run, 'approve', $this->approver)->assertOk()->json('data') : $run;
    };
    $this->slips = fn (array $run) => collect($this->asToken($this->runner)->getJson(($this->api)("runs/{$run['id']}"))->assertOk()->json('data.slips'))->keyBy('employee_name');
    $this->slip = fn (array $run, string $name) => $this->asToken($this->runner)->getJson(($this->api)("runs/{$run['id']}/slips/".($this->slips)($run)[$name]['id']))->assertOk()->json('data');

    // January and February paid at the old basics; Selim was away two days in February.
    $this->january = ($this->month)('2027-01');
    $this->february = ($this->month)('2027-02');
    Slip::query()->where('run_id', $this->february['id'])->where('employee_id', $this->selim['id'])->update(['absent_days' => 2]);

    // A 10% raise for both from January 1.
    $this->open = fn (array $data = []) => $this->asToken($this->runner)->postJson(($this->api)('increments'), ['title' => ['en' => 'Yearly increment 2027'], 'effective_from' => '2027-01-01', ...$data]);
    $this->roundStep = function (string $step, ?string $token = null) {
        $response = $this->asToken($token ?? $this->runner)->postJson(($this->api)("increments/{$this->round['id']}/{$step}"), ['base_version' => $this->round['version']]);
        if ($response->status() === 200) {
            $this->round = $response->json('data');
        }

        return $response;
    };
    $this->show = fn () => $this->asToken($this->runner)->getJson(($this->api)("increments/{$this->round['id']}"))->assertOk()->json('data');
    $this->raiseAll = function () {
        foreach (($this->show)()['lines'] as $line) {
            $this->asToken($this->runner)->patchJson(($this->api)("increments/{$this->round['id']}/lines/{$line['id']}"), ['rate_bp' => 1000, 'reason' => 'Yearly'])->assertOk();
        }
        $this->round = ($this->show)();
    };
    // A month's structure earnings for a basic, prorated over half days paid (half-up), as payroll does.
    $prorated = fn (int $amount, int $payable, int $whole) => intdiv($amount * $payable + intdiv($whole, 2), $whole);
    $this->expectedSelim = 600000 + ($prorated(4400000, 52, 56) - $prorated(4000000, 52, 56)) + ($prorated(2200000, 52, 56) - $prorated(2000000, 52, 56));
});

it('shows the arrears before approval, plans them when applied and pays them with the next payroll', function () {
    expect(($this->slips)($this->january)['Rahima Akter']['absent_days'])->toBe(0);
    $this->round = ($this->open)()->assertCreated()->json('data');
    ($this->raiseAll)();

    // Rahima: basic +3,000.00 and house rent +1,500.00 for two full months; Selim's February counts 26 of 28 days.
    $lines = collect($this->round['lines'])->keyBy('employee_name');
    expect($lines['Rahima Akter']['arrears_minor'])->toBe(900000)
        ->and($lines['Selim Reza']['arrears_minor'])->toBe($this->expectedSelim)
        ->and($this->round['arrears_minor'])->toBe(900000 + $this->expectedSelim);

    ($this->roundStep)('submit');
    ($this->roundStep)('approve', $this->approver);

    // March waiting for approval: its pay is not final, so the round waits.
    $march = ($this->month)('2027-03', approve: false);
    ($this->roundStep)('apply')->assertConflict()->assertJsonPath('code', 'increment_month_waiting');
    $march = ($this->runStep)($march, 'reject', $this->approver, ['reason' => 'Increment first'])->assertOk()->json('data');
    $this->round = ($this->show)();
    ($this->roundStep)('apply');
    expect($this->round['status'])->toBe('applied')
        ->and(IncrementArrear::query()->count())->toBe(4)
        ->and(AuditLog::query()->where('action', 'payroll.increment_applied')->where('new_values->arrears_minor', 900000 + $this->expectedSelim)->count())->toBe(1);

    $this->travel(2)->minutes(); // past the per-minute limit on sensitive writes
    // March: the new basic, and the arrears on their own line with provident fund on their basic part.
    $march = ($this->runStep)($march, 'calculate')->assertOk()->json('data');
    $slip = ($this->slip)($march, 'Rahima Akter');
    $line = fn (string $code, string $name) => collect($slip['lines'])->first(fn ($item) => $item['code'] === $code && str_contains($item['name'], $name));
    expect($slip['basic_minor'])->toBe(3300000)
        ->and($line('ARREARS', 'Increment arrears')['amount_minor'])->toBe(900000)
        ->and($line('ARREARS', 'Increment arrears')['name'])->toBe('Increment arrears (Jan 2027 – Feb 2027)')
        ->and($line('PF_EMPLOYEE', 'arrears')['amount_minor'])->toBe(60000);

    // Approved: paid; the round shows nothing waiting; the letter field tells the amount.
    $march = ($this->runStep)($march, 'submit')->assertOk()->json('data');
    ($this->runStep)($march, 'approve', $this->approver)->assertOk();
    expect(IncrementArrear::query()->where('status', IncrementArrear::PAID)->where('run_id', $march['id'])->count())->toBe(4)
        ->and(($this->show)()['arrears'])->toMatchArray(['paid_minor' => 900000 + $this->expectedSelim, 'waiting_minor' => 0, 'months' => ['2027-01', '2027-02']]);
    $fields = app(PayLetterFields::class)->values($this->w->c1, [$this->rahima['id']], 'en', ['type' => IncrementLetters::TYPE, 'id' => $this->round['id']]);
    expect($fields[$this->rahima['id']]['increment.arrears'])->toBe('BDT 9,000.00');
});

it('gives arrears back when the run taking them is deleted, and follows the rules', function () {
    $this->round = ($this->open)()->assertCreated()->json('data');
    ($this->raiseAll)();
    ($this->roundStep)('submit');
    ($this->roundStep)('approve', $this->approver);
    ($this->roundStep)('apply');

    $march = $this->asToken($this->runner)->postJson(($this->api)('runs'), ['period' => '2027-03'])->assertCreated()->json('data');
    $march = ($this->runStep)($march, 'calculate')->assertOk()->json('data');
    expect(IncrementArrear::query()->where('run_id', $march['id'])->count())->toBe(4);
    $this->asToken($this->runner)->deleteJson(($this->api)("runs/{$march['id']}"), ['base_version' => $march['version']])->assertNoContent();
    expect(IncrementArrear::query()->whereNull('run_id')->where('status', IncrementArrear::PLANNED)->count())->toBe(4);

    $this->travel(2)->minutes(); // past the per-minute limit on sensitive writes
    // Switched off: a round cannot start in a month already paid; too far back is refused.
    trustedOrgRule($this->w->c1, 'payroll.increment_arrears', false);
    ($this->open)(['effective_from' => '2027-02-01'])->assertConflict()->assertJsonPath('code', 'increment_month_locked');
    trustedOrgRule($this->w->c1, 'payroll.increment_arrears', true);
    trustedOrgRule($this->w->c1, 'payroll.increment_arrears_max_months', 1);
    ($this->open)(['effective_from' => '2027-01-01'])->assertUnprocessable()->assertJsonValidationErrors('effective_from');
    ($this->open)(['effective_from' => '2027-02-01'])->assertCreated();
});

it('taxes arrears as a one-off payment and takes the provident fund on their basic part', function () {
    $slabs = [[30000000, 0], [40000000, 1000], [null, 2000]];
    $rules = ['deduct_absence' => true, 'overtime_multiplier_bp' => 0, 'overtime_base' => 'basic', 'monthly_hours' => 208, 'late_policy' => 'none', 'tax_slabs' => $slabs];
    $facts = ['basic' => 3000000, 'items' => [], 'period_days' => 31, 'employed_days' => 31, 'absent_days' => 0, 'half_days' => 0, 'overtime_minutes' => 0, 'late_minutes' => 0, 'adjustments' => [], 'pf' => [1000, 1000]];
    $plain = PayCalculator::slip($facts, $rules);
    $with = PayCalculator::slip([...$facts, 'arrears' => [['name' => ['en' => 'Increment arrears (Jan 2027)'], 'amount' => 600000, 'taxable' => 600000, 'pf_base' => 600000]]], $rules);

    // Not twelve months of it: only the extra yearly tax the one payment brings.
    expect($with['tax'])->toBe($plain['tax'] + PayCalculator::bonusTax(3000000, 600000, $slabs))
        ->and($with['tax'])->toBeLessThan(PayCalculator::monthlyTax(3600000, $slabs))
        ->and($with['earnings'])->toBe($plain['earnings'] + 600000)
        ->and(collect($with['lines'])->where('code', 'PF_EMPLOYEE')->sum('amount'))->toBe(300000 + 60000)
        ->and(collect($with['lines'])->where('code', 'PF_EMPLOYER')->sum('amount'))->toBe(300000 + 60000);
});

it('pays the arrears of someone who left with their final settlement, once', function () {
    $this->round = ($this->open)()->assertCreated()->json('data');
    ($this->raiseAll)();
    ($this->roundStep)('submit');
    ($this->roundStep)('approve', $this->approver);
    ($this->roundStep)('apply');

    // Selim leaves on March 10; March's draft payroll takes everyone's arrears for now.
    $fresh = $this->asToken($this->w->token)->getJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$this->selim['id']}")->json('data');
    $this->asToken($this->w->token)->postJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$this->selim['id']}/steps/exit", ['base_version' => $fresh['version'], 'on' => '2027-03-10', 'reason' => 'Resigned'])->assertOk();
    $march = $this->asToken($this->runner)->postJson(($this->api)('runs'), ['period' => '2027-03'])->assertCreated()->json('data');
    $march = ($this->runStep)($march, 'calculate')->assertOk()->json('data');
    expect(IncrementArrear::query()->where('run_id', $march['id'])->count())->toBe(4);
    $this->travel(2)->minutes(); // past the per-minute limit on sensitive writes

    // His settlement: the arrears in full (no provident fund on them), taxed on their taxable part.
    $settle = fn (array $settlement, string $step, ?string $token = null, array $data = []) => $this->asToken($token ?? $this->runner)
        ->postJson(($this->api)("settlements/{$settlement['id']}/{$step}"), ['base_version' => $settlement['version'], ...$data]);
    $settlement = $this->asToken($this->runner)->postJson(($this->api)('settlements'), ['employee_id' => $this->selim['id']])->assertCreated()->json('data');
    $arrears = collect($settlement['lines'])->firstWhere('code', 'ARREARS');
    expect($arrears)->toMatchArray(['kind' => 'earning', 'amount_minor' => $this->expectedSelim])
        ->and($arrears['basis'])->toEqual(['key' => 'arrears', 'params' => ['from' => '2027-01', 'to' => '2027-02', 'taxable' => $this->expectedSelim]]);

    // Paid elsewhere meanwhile: the settlement must be worked out again.
    $one = IncrementArrear::query()->where('employee_id', $this->selim['id'])->orderBy('period')->first();
    $one->forceFill(['status' => IncrementArrear::PAID])->save();
    $settlement = $settle($settlement, 'submit')->assertOk()->json('data');
    $settle($settlement, 'approve', $this->approver)->assertConflict()->assertJsonPath('code', 'settlement_stale');
    $one->forceFill(['status' => IncrementArrear::PLANNED])->save();
    $settlement = $settle($settlement, 'reject', $this->approver, ['reason' => 'Work it out again'])->assertOk()->json('data');
    $settlement = $settle($settlement, 'calculate')->assertOk()->json('data');
    $settlement = $settle($settlement, 'submit')->assertOk()->json('data');
    $settle($settlement, 'approve', $this->approver)->assertOk();

    // Paid with the settlement; March must be worked out again and pays only Rahima's.
    expect(IncrementArrear::query()->where('employee_id', $this->selim['id'])->where('status', IncrementArrear::PAID)->where('settlement_id', $settlement['id'])->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'payroll.arrears_settled')->count())->toBe(1);
    $march = $this->asToken($this->runner)->getJson(($this->api)("runs/{$march['id']}"))->json('data');
    $march = ($this->runStep)($march, 'calculate')->assertOk()->json('data');
    $selims = collect(($this->slip)($march, 'Selim Reza')['lines'])->where('code', 'ARREARS');
    expect($selims)->toBeEmpty()
        ->and(collect(($this->slip)($march, 'Rahima Akter')['lines'])->firstWhere('code', 'ARREARS')['amount_minor'])->toBe(900000);
});
