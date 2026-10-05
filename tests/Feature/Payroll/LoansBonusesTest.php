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
 * PAY-3a: loans and advances recovered from salaries (approval by another
 * person, monthly instalments planned by draft runs and recovered at
 * approval, months held back, posting to the books) and festival bonuses
 * (eligibility by service, amounts set by hand, tax at source on top of
 * the regular pay, approval, posting, bank file, own view), with isolation.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2] as $group) {
        toggles()->enable($group, 'attendance', 'Test setup');
        toggles()->enable($group, 'payroll', 'Test setup');
    }
    $this->runner = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'payroll.view', 'payroll.run'], 'Payroll clerk')), $this->w->c1);
    $this->approver = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['payroll.view', 'payroll.approve'], 'Approver')), $this->w->c1);
    $this->workerUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['attendance.punch'], 'Worker'));
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/payroll/{$path}";

    $hire = fn (string $name, string $joined) => hireVia($this, $this->w->token, $this->w->b1, ['full_name' => $name, 'joined_on' => $joined])->assertCreated()->json('data');
    $this->rahima = $hire('Rahima Akter', '2026-10-01');
    $this->veteran = $hire('Selim Reza', '2025-01-01');
    $this->asToken($this->w->token)->putJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$this->rahima['id']}/login", ['base_version' => $this->rahima['version'], 'user_id' => $this->workerUser->id])->assertOk();

    $structure = $this->asToken($this->runner)->postJson(($this->api)('structures'), ['code' => 'PLAIN', 'name' => ['en' => 'Basic only'], 'items' => []])->assertCreated()->json('data');
    foreach ([[$this->rahima, 3000000, '2026-10-01'], [$this->veteran, 4000000, '2025-01-01']] as [$employee, $basic, $from]) {
        $this->asToken($this->runner)->postJson(($this->api)("employees/{$employee['id']}/salary"), ['structure_id' => $structure['id'], 'basic_minor' => $basic, 'effective_from' => $from])->assertCreated();
    }

    $this->loan = fn (array $data, ?string $token = null) => $this->asToken($token ?? $this->runner)->postJson(($this->api)('loans'), [
        'employee_id' => $this->rahima['id'], 'kind' => 'loan', 'principal_minor' => 6000000, 'installments' => 3,
        'start_period' => '2026-10', 'paid_out_on' => '2026-10-05', ...$data,
    ]);
    $this->loanStep = fn (array $loan, string $step, string $token, array $data = []) => $this->asToken($token)
        ->postJson(($this->api)("loans/{$loan['id']}/{$step}"), ['base_version' => $loan['version'], ...$data]);
    $this->openRun = fn (string $period) => $this->asToken($this->runner)->postJson(($this->api)('runs'), ['period' => $period])->assertCreated()->json('data');
    $this->step = fn (array $run, string $step, ?string $token = null, array $data = []) => $this->asToken($token ?? $this->runner)
        ->postJson(($this->api)("runs/{$run['id']}/{$step}"), ['base_version' => $run['version'], ...$data]);
    $this->slipOf = fn (array $run, string $name) => collect($this->asToken($this->runner)->getJson(($this->api)("runs/{$run['id']}"))->json('data.slips'))->firstWhere('employee_name', $name);
    $this->linesOf = fn (array $run, array $slip) => collect($this->asToken($this->runner)->getJson(($this->api)("runs/{$run['id']}/slips/{$slip['id']}"))->json('data.lines'));
});

it('asks for a loan within the rules, approved by someone else and posted', function () {
    ($this->loan)(['principal_minor' => 9000001])->assertUnprocessable()->assertJsonValidationErrors('principal_minor');
    ($this->loan)(['installments' => 25])->assertUnprocessable()->assertJsonValidationErrors('installments');
    ($this->loan)(['start_period' => '2026-09'])->assertUnprocessable()->assertJsonValidationErrors('start_period');

    toggles()->enable($this->w->g1, 'accounting', 'Test setup');
    $books = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['accounting.view', 'accounting.manage'], 'Books')), $this->w->c1);
    setUpBooks($this, $books, $this->w->c1)->assertCreated();

    $loan = ($this->loan)([])->assertCreated()->json('data');
    expect($loan)->toMatchArray(['status' => 'pending_approval', 'installment_minor' => 2000000, 'balance_minor' => 6000000])
        ->and($loan['can'])->toMatchArray(['approve' => false, 'cancel' => true])
        ->and(collect($loan['schedule'])->pluck('amount_minor', 'period')->all())->toBe(['2026-10' => 2000000, '2026-11' => 2000000, '2026-12' => 2000000]);

    ($this->loanStep)($loan, 'approve', $this->runner)->assertForbidden();
    ($this->loanStep)($loan, 'reject', $this->approver)->assertUnprocessable()->assertJsonValidationErrors('note');
    $loan = ($this->loanStep)($loan, 'approve', $this->approver)->assertOk()->json('data');
    expect($loan['status'])->toBe('active')->and($loan['journal_id'])->not->toBeNull();
    ($this->loanStep)($loan, 'cancel', $this->runner)->assertConflict()->assertJsonPath('code', 'loan_not_pending');

    $row = fn (string $code) => collect($this->asToken($books)->getJson("/api/organizations/{$this->w->c1->id}/accounting/reports/trial-balance?as_of=2026-11-02")->json('data.rows'))->firstWhere('code', $code);
    expect($row('1160')['debit_minor'])->toBe(6000000);

    // October's payroll recovers the first instalment, against the loan (not other deductions).
    $run = ($this->step)(($this->openRun)('2026-10'), 'calculate')->assertOk()->json('data');
    $slip = ($this->slipOf)($run, 'Rahima Akter');
    expect($slip['deductions_minor'])->toBe(2000000)
        ->and(($this->linesOf)($run, $slip)->firstWhere('code', 'LOAN')['amount_minor'])->toBe(2000000);
    $run = ($this->step)($run, 'submit')->assertOk()->json('data');
    ($this->step)($run, 'approve', $this->approver)->assertOk();

    $loan = $this->asToken($this->runner)->getJson(($this->api)("loans/{$loan['id']}"))->assertOk()->json('data');
    expect($loan)->toMatchArray(['recovered_minor' => 2000000, 'balance_minor' => 4000000])
        ->and(collect($loan['schedule'])->firstWhere('period', '2026-10')['status'])->toBe('recovered')
        ->and($row('1160')['debit_minor'])->toBe(4000000)
        ->and($row('2140'))->toBeNull()
        ->and(AuditLog::query()->where('action', 'payroll.loan_approved')->count())->toBe(1);
});

it('holds a month back while its payroll is a draft, and never recovers more than is owed', function () {
    $loan = ($this->loanStep)(($this->loan)(['kind' => 'advance', 'principal_minor' => 1500000, 'installments' => 1])->json('data'), 'approve', $this->approver)->assertOk()->json('data');

    // Two drafts at once: October plans the whole advance, November nothing.
    $october = ($this->step)(($this->openRun)('2026-10'), 'calculate')->json('data');
    $november = ($this->step)(($this->openRun)('2026-11'), 'calculate')->json('data');
    expect(($this->slipOf)($october, 'Rahima Akter')['deductions_minor'])->toBe(1500000)
        ->and(($this->slipOf)($november, 'Rahima Akter')['deductions_minor'])->toBe(0);

    // Holding October back: its draft needs calculating again, then November takes it.
    $this->asToken($this->runner)->postJson(($this->api)("loans/{$loan['id']}/skips"), ['period' => '2026-10', 'reason' => 'Medical leave month'])->assertCreated();
    $october = $this->asToken($this->runner)->getJson(($this->api)("runs/{$october['id']}"))->json('data');
    expect($october['calculated_at'])->toBeNull();
    $october = ($this->step)($october, 'calculate')->json('data');
    $november = ($this->step)($november, 'calculate')->json('data');
    expect(($this->slipOf)($october, 'Rahima Akter')['deductions_minor'])->toBe(0)
        ->and(($this->slipOf)($november, 'Rahima Akter')['deductions_minor'])->toBe(1500000);

    // Once October is sent its month is locked.
    ($this->step)($october, 'submit')->assertOk();
    $this->asToken($this->runner)->deleteJson(($this->api)("loans/{$loan['id']}/skips/2026-10"))->assertConflict()->assertJsonPath('code', 'month_locked');
    $this->asToken($this->runner)->postJson(($this->api)("loans/{$loan['id']}/skips"), ['period' => '2026-10', 'reason' => 'Again'])->assertConflict();

    // November approved: the advance is paid back and closes.
    $november = ($this->step)($november, 'submit')->json('data');
    ($this->step)($november, 'approve', $this->approver)->assertOk();
    $this->asToken($this->runner)->getJson(($this->api)("loans/{$loan['id']}"))->assertJsonPath('data.status', 'closed')->assertJsonPath('data.balance_minor', 0);
    expect(AuditLog::query()->where('action', 'payroll.loan_closed')->count())->toBe(1);
});

it('pays a festival bonus by service, with amounts set by hand and tax on top of the regular pay', function () {
    trustedOrgRule($this->w->c1, 'payroll.bonus_min_service_months', 12);
    // Yearly: first 350,000.00 free, next 100,000.00 at 5%, the rest at 10%.
    platformRule('payroll.tax_slabs', [['upto_minor' => 35000000, 'rate_percent' => '0'], ['upto_minor' => 45000000, 'rate_percent' => '5'], ['upto_minor' => null, 'rate_percent' => '10']]);

    $bonus = $this->asToken($this->runner)->postJson(($this->api)('bonuses'), ['title' => ['en' => 'Eid-ul-Adha', 'bn' => 'ঈদুল আজহা'], 'bonus_on' => '2026-11-01'])->assertCreated()->json('data');
    expect($bonus)->toMatchArray(['rate_bp' => 10000, 'status' => 'draft']);
    $step = fn (array $bonus, string $step, ?string $token = null, array $data = []) => $this->asToken($token ?? $this->runner)
        ->postJson(($this->api)("bonuses/{$bonus['id']}/{$step}"), ['base_version' => $bonus['version'], ...$data]);
    $lines = fn () => collect($this->asToken($this->runner)->getJson(($this->api)("bonuses/{$bonus['id']}"))->json('data.lines'))->keyBy('employee_name');

    ($step)($bonus, 'submit')->assertConflict()->assertJsonPath('code', 'not_calculated');
    $bonus = ($step)($bonus, 'calculate')->assertOk()->json('data');
    expect($lines()['Selim Reza'])->toMatchArray(['service_months' => 22, 'gross_minor' => 4000000, 'tax_minor' => 400000, 'net_minor' => 3600000, 'not_paid_reason' => null])
        ->and($lines()['Rahima Akter'])->toMatchArray(['service_months' => 1, 'gross_minor' => 0, 'not_paid_reason' => 'short_service']);

    // A part bonus for Rahima by hand; it stays when calculated again.
    $this->asToken($this->runner)->patchJson(($this->api)("bonuses/{$bonus['id']}/lines/{$lines()['Rahima Akter']['id']}"), ['override_minor' => 500000])->assertOk()
        ->assertJsonPath('data.net_minor', 475000)->assertJsonPath('data.tax_minor', 25000);
    $bonus = $this->asToken($this->runner)->getJson(($this->api)("bonuses/{$bonus['id']}"))->json('data');
    $bonus = ($step)($bonus, 'calculate')->json('data');
    expect($bonus)->toMatchArray(['employees' => 2, 'gross_minor' => 4500000, 'tax_minor' => 425000, 'net_minor' => 4075000]);

    $bonus = ($step)($bonus, 'submit')->assertOk()->json('data');
    ($step)($bonus, 'approve')->assertForbidden();
    $bonus = ($step)($bonus, 'approve', $this->approver)->assertOk()->json('data');
    $this->asToken($this->runner)->patchJson(($this->api)("bonuses/{$bonus['id']}/lines/{$lines()['Selim Reza']['id']}"), ['excluded' => true])->assertConflict();

    $file = $this->asToken($this->runner)->getJson(($this->api)("bonuses/{$bonus['id']}/bank-file"))->assertOk()->json('data');
    expect(collect($file['rows'])->pluck('amount_minor', 'employee_name')->all())->toBe(['Rahima Akter' => 475000, 'Selim Reza' => 3600000])
        ->and(AuditLog::query()->where('action', 'payroll.bonus_bank_file_taken')->count())->toBe(1);
    ($step)($bonus, 'pay', null, ['paid_on' => '2026-11-02'])->assertOk()->assertJsonPath('data.status', 'paid');

    // Rahima sees her own bonus only.
    $worker = orgToken($this->workerUser, $this->w->c1);
    $mine = $this->asToken($worker)->getJson(($this->api)('me/bonuses'))->assertOk()->json('data');
    expect($mine)->toHaveCount(1)->and($mine[0])->toMatchArray(['title' => 'Eid-ul-Adha', 'net_minor' => 475000, 'paid_on' => '2026-11-02']);
    $this->asToken($worker)->getJson(($this->api)("me/bonuses/{$lines()['Selim Reza']['id']}"))->assertNotFound();
    $this->asToken($worker)->getJson(($this->api)('bonuses'))->assertForbidden();
});

it('keeps loans and bonuses to the company, the employee and the portal member', function () {
    $loan = ($this->loanStep)(($this->loan)([])->json('data'), 'approve', $this->approver)->json('data');
    $bonus = $this->asToken($this->runner)->postJson(($this->api)('bonuses'), ['title' => ['en' => 'Puja'], 'bonus_on' => '2026-10-20', 'rate_bp' => 5000])->assertCreated()->json('data');

    $c2 = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['payroll.view', 'payroll.run'], 'C2 payroll')), $this->w->c2);
    $this->asToken($c2)->getJson(($this->api)("loans/{$loan['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($c2)->getJson(($this->api)("bonuses/{$bonus['id']}", $this->w->c2))->assertNotFound();
    expect($this->asToken($c2)->getJson(($this->api)('loans', $this->w->c2))->json('data'))->toBe([]);
    $this->asToken($c2)->postJson(($this->api)('loans', $this->w->c2), ['employee_id' => $this->rahima['id'], 'kind' => 'loan', 'principal_minor' => 100, 'installments' => 1, 'start_period' => '2026-11', 'paid_out_on' => '2026-11-01'])
        ->assertNotFound();

    // Rahima's loan in the app and in the portal; a portal member linked to Selim sees none.
    $worker = orgToken($this->workerUser, $this->w->c1);
    expect($this->asToken($worker)->getJson(($this->api)('me/loans'))->assertOk()->json('data.0'))->toMatchArray(['id' => $loan['id'], 'balance_minor' => 6000000]);
    toggles()->enable($this->w->g1, 'client_portal', 'Test setup');
    $member = User::factory()->create(['email_verified_at' => now()]);
    app(AddMember::class)->handle($this->w->c1, $member, MembershipType::Portal, AccessScope::Own);
    $membership = OrganizationMembership::query()->where('user_id', $member->id)->where('organization_id', $this->w->c1->id)->sole();
    (new PortalLink)->forceFill([
        'organization_id' => $this->w->c1->id, 'membership_id' => $membership->id, 'user_id' => $member->id,
        'subject_type' => 'hrm.employee', 'subject_id' => $this->veteran['id'], 'relation' => 'self', 'status' => PortalLink::ACTIVE, 'linked_via' => 'invitation',
    ])->save();
    $portal = orgToken($member, $this->w->c1);
    expect($this->asToken($portal)->getJson('/api/portal/payroll/loans')->assertOk()->json('data'))->toBe([])
        ->and($this->asToken($portal)->getJson('/api/portal/payroll/bonuses')->assertOk()->json('data'))->toBe([]);
    $this->asToken($portal)->getJson(($this->api)("loans/{$loan['id']}"))->assertForbidden();

    toggles()->disable($this->w->g1, 'payroll', 'Test setup', confirm: true);
    $this->asToken($this->runner)->getJson(($this->api)('loans'))->assertForbidden();
    $this->asToken($this->runner)->getJson(($this->api)('bonuses'))->assertForbidden();
});

it('works out instalments, service and bonus tax with integers', function () {
    expect(PayCalculator::installment(1000, 3))->toBe(334)
        ->and(PayCalculator::installment(900, 3))->toBe(300)
        ->and(PayCalculator::serviceMonths('2025-01-15', '2026-01-14'))->toBe(11)
        ->and(PayCalculator::serviceMonths('2025-01-15', '2026-01-15'))->toBe(12)
        ->and(PayCalculator::serviceMonths('2026-12-01', '2026-11-01'))->toBe(0)
        ->and(PayCalculator::bonusTax(4000000, 4000000, [[35000000, 0], [45000000, 500], [null, 1000]]))->toBe(400000)
        ->and(PayCalculator::bonusTax(1000000, 1000000, [[35000000, 0], [null, 1000]]))->toBe(0);
});
