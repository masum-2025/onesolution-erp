<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Modules\Payroll\Services\PayCalculator;

/*
 * PAY-3b: the provident fund (shares taken by monthly payroll, the fund
 * ledger, posting) and final settlements of people who left: gratuity, the
 * fund paid out as far as vested (the rest kept back), loans still owed,
 * lines by hand with tax, approval by someone else, posting, own view and
 * isolation.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2] as $group) {
        toggles()->enable($group, 'attendance', 'Test setup');
        toggles()->enable($group, 'payroll', 'Test setup');
        toggles()->enable($group, 'accounting', 'Test setup');
    }
    $this->runner = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'payroll.view', 'payroll.run'], 'Payroll clerk')), $this->w->c1);
    $this->approver = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['payroll.view', 'payroll.approve'], 'Approver')), $this->w->c1);
    $this->books = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['accounting.view', 'accounting.manage'], 'Books')), $this->w->c1);
    setUpBooks($this, $this->books, $this->w->c1)->assertCreated();
    $this->workerUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['attendance.punch'], 'Worker'));
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/payroll/{$path}";

    trustedOrgRule($this->w->c1, 'payroll.pf_enabled', true);
    trustedOrgRule($this->w->c1, 'payroll.pf_vesting', [['after_years' => 3, 'percent' => '50'], ['after_years' => 5, 'percent' => '100']]);
    trustedOrgRule($this->w->c1, 'payroll.gratuity_min_years', 5);
    trustedOrgRule($this->w->c1, 'payroll.gratuity_days_per_year', 30);

    $hire = fn (string $name, string $joined) => hireVia($this, $this->w->token, $this->w->b1, ['full_name' => $name, 'joined_on' => $joined])->assertCreated()->json('data');
    $this->veteran = $hire('Selim Reza', '2020-01-01');
    $this->rahima = $hire('Rahima Akter', '2026-10-01');
    $this->asToken($this->w->token)->putJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$this->veteran['id']}/login", ['base_version' => $this->veteran['version'], 'user_id' => $this->workerUser->id])->assertOk();

    $structure = $this->asToken($this->runner)->postJson(($this->api)('structures'), ['code' => 'PLAIN', 'name' => ['en' => 'Basic only'], 'items' => []])->assertCreated()->json('data');
    foreach ([[$this->veteran, 4000000, '2020-01-01'], [$this->rahima, 3000000, '2026-10-01']] as [$employee, $basic, $from]) {
        $this->asToken($this->runner)->postJson(($this->api)("employees/{$employee['id']}/salary"), ['structure_id' => $structure['id'], 'basic_minor' => $basic, 'effective_from' => $from])->assertCreated();
    }

    $this->step = fn (string $what, array $item, string $step, ?string $token = null, array $data = []) => $this->asToken($token ?? $this->runner)
        ->postJson(($this->api)("{$what}/{$item['id']}/{$step}"), ['base_version' => $item['version'], ...$data]);
    // October paid through payroll: the fund gets its first month.
    $this->october = function () {
        $run = $this->asToken($this->runner)->postJson(($this->api)('runs'), ['period' => '2026-10'])->assertCreated()->json('data');
        $run = ($this->step)('runs', ($this->step)('runs', $run, 'calculate')->assertOk()->json('data'), 'submit')->assertOk()->json('data');

        return ($this->step)('runs', $run, 'approve', $this->approver)->assertOk()->json('data');
    };
    $this->leave = function (array $employee, string $on) {
        $fresh = $this->asToken($this->w->token)->getJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$employee['id']}")->json('data');
        $this->asToken($this->w->token)->postJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$employee['id']}/steps/exit", ['base_version' => $fresh['version'], 'on' => $on, 'reason' => 'Resigned'])->assertOk();
    };
    // Someone who left sees their own pay through the client's portal (their staff login no longer maps to them).
    $this->portalFor = function (array $employee) {
        toggles()->enable($this->w->g1, 'client_portal', 'Test setup');
        $member = User::factory()->create(['email_verified_at' => now()]);
        app(AddMember::class)->handle($this->w->c1, $member, MembershipType::Portal, AccessScope::Own);
        $membership = OrganizationMembership::query()->where('user_id', $member->id)->where('organization_id', $this->w->c1->id)->sole();
        (new PortalLink)->forceFill([
            'organization_id' => $this->w->c1->id, 'membership_id' => $membership->id, 'user_id' => $member->id,
            'subject_type' => 'hrm.employee', 'subject_id' => $employee['id'], 'relation' => 'self', 'status' => PortalLink::ACTIVE, 'linked_via' => 'invitation',
        ])->save();

        return orgToken($member, $this->w->c1);
    };
    $this->row = fn (string $code) => collect($this->asToken($this->books)->getJson("/api/organizations/{$this->w->c1->id}/accounting/reports/trial-balance?as_of=2026-11-02")->json('data.rows'))->firstWhere('code', $code);
});

it('takes both provident fund shares with the month, into the fund and the books', function () {
    $run = ($this->october)();
    $slips = collect($this->asToken($this->runner)->getJson(($this->api)("runs/{$run['id']}"))->json('data.slips'))->keyBy('employee_name');
    expect($slips['Selim Reza'])->toMatchArray(['earnings_minor' => 4000000, 'deductions_minor' => 400000, 'net_minor' => 3600000]);
    $lines = collect($this->asToken($this->runner)->getJson(($this->api)("runs/{$run['id']}/slips/{$slips['Selim Reza']['id']}"))->json('data.lines'))->keyBy('code');
    expect($lines['PF_EMPLOYEE']['amount_minor'])->toBe(400000)->and($lines['PF_EMPLOYER'])->toMatchArray(['kind' => 'employer', 'amount_minor' => 400000]);

    // Both shares of both people are owed to them; the company's share is its expense.
    expect(($this->row)('2160')['credit_minor'])->toBe(2 * (400000 + 300000))
        ->and(($this->row)('2140'))->toBeNull()
        ->and(($this->row)('5200')['debit_minor'])->toBe(4000000 + 3000000 + 400000 + 300000);

    $fund = collect($this->asToken($this->runner)->getJson(($this->api)('fund'))->assertOk()->json('data'))->keyBy('employee_name');
    expect($fund['Selim Reza'])->toMatchArray(['employee_minor' => 400000, 'employer_minor' => 400000, 'last_period' => '2026-10']);
    $mine = $this->asToken(orgToken($this->workerUser, $this->w->c1))->getJson(($this->api)('me/fund'))->assertOk()->json('data');
    expect($mine)->toMatchArray(['employee_minor' => 400000, 'employer_minor' => 400000])->and($mine['entries'])->toHaveCount(1);
});

it('settles someone who left: gratuity, the fund, loans owed and lines by hand, approved by someone else', function () {
    platformRule('payroll.tax_slabs', [['upto_minor' => 35000000, 'rate_percent' => '0'], ['upto_minor' => 45000000, 'rate_percent' => '5'], ['upto_minor' => null, 'rate_percent' => '10']]);
    $loan = $this->asToken($this->runner)->postJson(($this->api)('loans'), ['employee_id' => $this->veteran['id'], 'kind' => 'loan', 'principal_minor' => 1200000, 'installments' => 6, 'start_period' => '2026-10', 'paid_out_on' => '2026-10-01'])->json('data');
    ($this->step)('loans', $loan, 'approve', $this->approver)->assertOk();
    ($this->october)();

    $this->asToken($this->runner)->postJson(($this->api)('settlements'), ['employee_id' => $this->veteran['id']])->assertConflict()->assertJsonPath('code', 'not_leaving');
    ($this->leave)($this->veteran, '2026-12-31');
    expect($this->asToken($this->runner)->getJson(($this->api)('settlements'))->json('meta.due.0.name'))->toBe('Selim Reza');

    $settlement = $this->asToken($this->runner)->postJson(($this->api)('settlements'), ['employee_id' => $this->veteran['id']])->assertCreated()->json('data');
    $this->asToken($this->runner)->postJson(($this->api)('settlements'), ['employee_id' => $this->veteran['id']])->assertConflict();
    $lines = collect($settlement['lines'])->keyBy('code');
    // Seven whole years at the last day; 30 days of basic each; the fund in full (vested), the loan's rest.
    expect($settlement)->toMatchArray(['service_years' => 7, 'basic_minor' => 4000000])
        ->and($lines['GRATUITY'])->toMatchArray(['amount_minor' => 28000000, 'basis' => ['key' => 'gratuity', 'params' => ['years' => 7, 'days' => 30, 'min' => 5]]])
        ->and($lines['PF_EMPLOYEE']['amount_minor'])->toBe(400000)
        ->and($lines['PF_EMPLOYER']['amount_minor'])->toBe(400000)
        ->and($lines->has('PF_FORFEIT'))->toBeFalse()
        ->and($lines['LOAN'])->toMatchArray(['kind' => 'deduction', 'amount_minor' => 1000000]);

    // Notice pay by hand: taxed on top of a year of regular pay (480,000.00 a year).
    $settlement = $this->asToken($this->runner)->postJson(($this->api)("settlements/{$settlement['id']}/lines"), ['kind' => 'earning', 'label' => 'Notice pay', 'amount_minor' => 4000000])->assertCreated()->json('data');
    expect($settlement)->toMatchArray(['earnings_minor' => 28000000 + 800000 + 4000000, 'deductions_minor' => 1000000, 'tax_minor' => 400000, 'net_minor' => 32800000 - 1000000 - 400000]);
    $gratuity = collect($settlement['lines'])->firstWhere('code', 'GRATUITY');
    $this->asToken($this->runner)->deleteJson(($this->api)("settlements/{$settlement['id']}/lines/{$gratuity['id']}"))->assertConflict()->assertJsonPath('code', 'settlement_line_fixed');

    $settlement = ($this->step)('settlements', $settlement, 'submit')->assertOk()->json('data');
    ($this->step)('settlements', $settlement, 'approve')->assertForbidden();
    $settlement = ($this->step)('settlements', $settlement, 'approve', $this->approver)->assertOk()->json('data');
    expect($settlement['status'])->toBe('approved')->and($settlement['journal_id'])->not->toBeNull()->and($settlement['fund'])->toBe(['employee_minor' => 0, 'employer_minor' => 0]);
    $this->asToken($this->runner)->getJson(($this->api)("loans/{$loan['id']}"))->assertJsonPath('data.status', 'closed')->assertJsonPath('data.balance_minor', 0);
    expect(($this->row)('2160')['credit_minor'])->toBe(2 * 300000)
        ->and(($this->row)('1160'))->toBeNull()
        ->and(AuditLog::query()->where('action', 'payroll.settlement_approved')->count())->toBe(1);

    $this->asToken($this->runner)->getJson(($this->api)("settlements/{$settlement['id']}/bank-file"))->assertOk()->assertJsonPath('data.rows.0.amount_minor', 31400000);
    ($this->step)('settlements', $settlement, 'pay', null, ['paid_on' => '2026-11-02'])->assertOk()->assertJsonPath('data.status', 'paid');

    $portal = ($this->portalFor)($this->veteran);
    $mine = $this->asToken($portal)->getJson('/api/portal/payroll/settlements')->assertOk()->json('data');
    expect($mine)->toHaveCount(1);
    $this->asToken($portal)->getJson("/api/portal/payroll/settlements/{$mine[0]['id']}")->assertOk()->assertJsonPath('data.net_minor', 31400000)->assertJsonPath('data.lines.0.code', 'GRATUITY');
    expect($this->asToken($portal)->getJson('/api/portal/payroll/fund')->assertOk()->json('data'))->toMatchArray(['employee_minor' => 0, 'employer_minor' => 0]);
    // Their staff login no longer maps to them once they left.
    $this->asToken(orgToken($this->workerUser, $this->w->c1))->getJson(($this->api)('me/settlements'))->assertNotFound();
});

it('keeps back the company share not yet vested, and will not send a settlement that leaves the person owing', function () {
    ($this->october)();
    ($this->leave)($this->rahima, '2026-11-30');
    $settlement = $this->asToken($this->runner)->postJson(($this->api)('settlements'), ['employee_id' => $this->rahima['id']])->assertCreated()->json('data');
    $lines = collect($settlement['lines'])->keyBy('code');
    expect($lines['PF_EMPLOYEE']['amount_minor'])->toBe(300000)
        ->and($lines->has('PF_EMPLOYER'))->toBeFalse()
        ->and($lines['PF_FORFEIT'])->toMatchArray(['kind' => 'info', 'amount_minor' => 300000])
        ->and($lines->has('GRATUITY'))->toBeFalse()
        ->and($settlement['net_minor'])->toBe(300000);

    $owing = $this->asToken($this->runner)->postJson(($this->api)("settlements/{$settlement['id']}/lines"), ['kind' => 'deduction', 'label' => 'Laptop not returned', 'amount_minor' => 500000])->json('data');
    expect($owing['net_minor'])->toBe(-200000)->and($owing['can']['submit'])->toBeFalse();
    ($this->step)('settlements', $owing, 'submit')->assertConflict()->assertJsonPath('code', 'settlement_owed');
    $owing = $this->asToken($this->runner)->deleteJson(($this->api)("settlements/{$settlement['id']}/lines/".collect($owing['lines'])->firstWhere('code', 'MANUAL')['id']))->assertOk()->json('data');

    $settlement = ($this->step)('settlements', $owing, 'submit')->assertOk()->json('data');
    ($this->step)('settlements', $settlement, 'approve', $this->approver)->assertOk();
    // The company's share kept back comes off its expense; the fund is empty.
    expect(($this->row)('2160')['credit_minor'])->toBe(2 * 400000);
    $fund = collect($this->asToken($this->runner)->getJson(($this->api)('fund'))->json('data'))->keyBy('employee_name');
    expect($fund['Rahima Akter'])->toMatchArray(['employee_minor' => 0, 'employer_minor' => 0]);
});

it('keeps the fund and settlements to the company and to the employee', function () {
    ($this->october)();
    ($this->leave)($this->veteran, '2026-12-31');
    $settlement = $this->asToken($this->runner)->postJson(($this->api)('settlements'), ['employee_id' => $this->veteran['id']])->json('data');

    $c2 = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['payroll.view', 'payroll.run'], 'C2 payroll')), $this->w->c2);
    $this->asToken($c2)->getJson(($this->api)("settlements/{$settlement['id']}", $this->w->c2))->assertNotFound();
    expect($this->asToken($c2)->getJson(($this->api)('fund', $this->w->c2))->json('data'))->toBe([]);
    $this->asToken($c2)->postJson(($this->api)('settlements', $this->w->c2), ['employee_id' => $this->veteran['id']])->assertNotFound();

    // A draft is not the employee's to see yet; another person's never.
    $portal = ($this->portalFor)($this->veteran);
    expect($this->asToken($portal)->getJson('/api/portal/payroll/settlements')->json('data'))->toBe([]);
    $this->asToken($portal)->getJson("/api/portal/payroll/settlements/{$settlement['id']}")->assertNotFound();
    $this->asToken($portal)->getJson(($this->api)('settlements'))->assertForbidden();
    $other = ($this->portalFor)($this->rahima);
    expect($this->asToken($other)->getJson('/api/portal/payroll/fund')->json('data'))->toMatchArray(['employee_minor' => 300000]);

    // Payroll staff hear of people who left without one; approvers of those waiting.
    $bell = fn (string $token) => collect($this->asToken($token)->getJson('/api/attention')->json('data'))->pluck('count', 'key');
    ($this->leave)($this->rahima, '2026-11-30');
    expect($bell($this->runner)['payroll.settlements_due'] ?? null)->toBe(1);
    ($this->step)('settlements', $settlement, 'submit')->assertOk();
    expect($bell($this->approver)['payroll.settlements_waiting'] ?? null)->toBe(1);

    toggles()->disable($this->w->g1, 'payroll', 'Test setup', confirm: true);
    $this->asToken($this->runner)->getJson(($this->api)('settlements'))->assertForbidden();
});

it('works out gratuity and vesting with integers', function () {
    expect(PayCalculator::gratuity(4000000, 7, 5, 30))->toBe(28000000)
        ->and(PayCalculator::gratuity(4000000, 4, 5, 30))->toBe(0)
        ->and(PayCalculator::gratuity(3000000, 1, 0, 15))->toBe(1500000)
        ->and(PayCalculator::gratuity(3000000, 0, 0, 30))->toBe(0)
        ->and(PayCalculator::gratuity(3333333, 2, 1, 0))->toBe(0)
        ->and(PayCalculator::vestedShare(2, [[3, 5000], [5, 10000]]))->toBe(0)
        ->and(PayCalculator::vestedShare(4, [[3, 5000], [5, 10000]]))->toBe(5000)
        ->and(PayCalculator::vestedShare(9, [[3, 5000], [5, 10000]]))->toBe(10000)
        ->and(PayCalculator::vestedShare(0, []))->toBe(10000);
});
