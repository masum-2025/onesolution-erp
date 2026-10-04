<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;

/*
 * PAY-2: what the Payroll screens read besides the PAY-1 API: the salary
 * list, the bank file (full numbers behind a recent second step, audited,
 * never cached), payslips in the client's portal and the links to them,
 * the approvers' bell and the dashboard.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2] as $group) {
        toggles()->enable($group, 'attendance', 'Test setup');
        toggles()->enable($group, 'payroll', 'Test setup');
    }
    $this->runnerUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'payroll.view', 'payroll.run'], 'Payroll clerk'));
    $this->runner = orgToken($this->runnerUser, $this->w->c1);
    $this->approver = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['payroll.view', 'payroll.approve'], 'Approver')), $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/payroll/{$path}";

    $hire = fn (string $name) => hireVia($this, $this->w->token, $this->w->b1, ['full_name' => $name, 'joined_on' => '2026-10-01'])->assertCreated()->json('data');
    $this->rahima = $hire('Rahima Akter');
    $this->karim = $hire('Karim Uddin');
    $this->structure = $this->asToken($this->runner)->postJson(($this->api)('structures'), ['code' => 'STAFF', 'name' => ['en' => 'Staff'], 'items' => []])->assertCreated()->json('data');
    $this->asToken($this->runner)->postJson(($this->api)("employees/{$this->rahima['id']}/salary"), [
        'structure_id' => $this->structure['id'], 'basic_minor' => 3000000, 'effective_from' => '2026-10-01',
    ])->assertCreated();
    $this->asToken($this->runner)->putJson(($this->api)("employees/{$this->rahima['id']}/payment"), [
        'method' => 'bank', 'provider' => 'Dutch-Bangla Bank', 'account_name' => 'Rahima Akter', 'account_number' => '1234-5678-9012',
    ])->assertOk();

    $this->step = fn (array $run, string $step, ?string $token = null, array $data = []) => $this->asToken($token ?? $this->runner)
        ->postJson(($this->api)("runs/{$run['id']}/{$step}"), ['base_version' => $run['version'], ...$data]);
    // October with Karim's salary still missing: Rahima's slip only once he is paid.
    $this->approvedRun = function () {
        $this->asToken($this->runner)->postJson(($this->api)("employees/{$this->karim['id']}/salary"), [
            'structure_id' => $this->structure['id'], 'basic_minor' => 2000000, 'effective_from' => '2026-10-01',
        ])->assertCreated();
        $run = $this->asToken($this->runner)->postJson(($this->api)('runs'), ['period' => '2026-10'])->assertCreated()->json('data');
        $run = ($this->step)(($this->step)($run, 'calculate')->assertOk()->json('data'), 'submit')->assertOk()->json('data');

        return ($this->step)($run, 'approve', $this->approver)->assertOk()->json('data');
    };
});

it('lists salaries with the structure and payment method, never an account number', function () {
    $response = $this->asToken($this->runner)->getJson(($this->api)('employees'))->assertOk()->assertJsonPath('meta.currency', 'BDT');
    $rows = collect($response->json('data'))->keyBy('name');
    expect($rows['Rahima Akter'])->toMatchArray(['basic_minor' => 3000000, 'structure' => 'Staff', 'payment_method' => 'bank'])
        ->and($rows['Karim Uddin'])->toMatchArray(['basic_minor' => null, 'structure' => null, 'payment_method' => null])
        ->and($response->getContent())->not->toContain('9012');

    expect($this->asToken($this->runner)->getJson(($this->api)('employees?search=karim'))->json('data'))->toHaveCount(1);
    $this->asToken($this->runner)->getJson(($this->api)("employees/{$this->rahima['id']}"))->assertOk()
        ->assertJsonPath('meta.currency', 'BDT')->assertJsonPath('data.payment.account_number', '••••9012');

    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view'], 'HR viewer')), $this->w->c1);
    $this->asToken($viewer)->getJson(($this->api)('employees'))->assertForbidden();
    $c2 = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['payroll.view'], 'C2 payroll')), $this->w->c2);
    expect($this->asToken($c2)->getJson(($this->api)('employees', $this->w->c2))->assertOk()->json('data'))->toBe([]);
});

it('keeps the account number on file when a change sends none', function () {
    $this->asToken($this->runner)->putJson(($this->api)("employees/{$this->rahima['id']}/payment"), ['method' => 'mobile', 'provider' => 'bKash'])->assertOk()
        ->assertJsonPath('data.account_number', '••••9012')->assertJsonPath('data.provider', 'bKash');
    // Nobody on file yet: a number is needed.
    $this->asToken($this->runner)->putJson(($this->api)("employees/{$this->karim['id']}/payment"), ['method' => 'bank'])->assertUnprocessable()->assertJsonValidationErrors('account_number');
    $this->asToken($this->runner)->putJson(($this->api)("employees/{$this->karim['id']}/payment"), ['method' => 'cash'])->assertOk()->assertJsonPath('data.account_number', null);
});

it('gives the bank file of an approved month in full, audited, uncached, after a recent second step', function () {
    $run = $this->asToken($this->runner)->postJson(($this->api)('runs'), ['period' => '2026-10'])->assertCreated()->json('data');
    $this->asToken($this->runner)->getJson(($this->api)("runs/{$run['id']}/bank-file"))->assertConflict();
    $this->asToken($this->runner)->deleteJson(($this->api)("runs/{$run['id']}"), ['base_version' => $run['version']])->assertNoContent();

    $run = ($this->approvedRun)();
    $response = $this->asToken($this->runner)->getJson(($this->api)("runs/{$run['id']}/bank-file"))->assertOk()
        ->assertJsonPath('data.period', '2026-10')->assertJsonPath('data.currency', 'BDT');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $rows = collect($response->json('data.rows'))->keyBy('employee_name');
    expect($rows['Rahima Akter'])->toMatchArray(['method' => 'bank', 'provider' => 'Dutch-Bangla Bank', 'account_number' => '1234-5678-9012', 'amount_minor' => $rows['Rahima Akter']['amount_minor']])
        ->and($rows['Rahima Akter']['amount_minor'])->toBeGreaterThan(0)
        ->and($rows['Karim Uddin']['method'])->toBeNull();

    $audit = AuditLog::query()->where('action', 'payroll.bank_file_taken')->sole();
    expect($audit->new_values)->toMatchArray(['period' => '2026-10', 'lines' => 2, 'without_account' => 1])
        ->and(json_encode($audit->new_values))->not->toContain('5678');

    // Approvers read payroll, they do not take bank files.
    $this->asToken($this->approver)->getJson(($this->api)("runs/{$run['id']}/bank-file"))->assertForbidden();
    $c2 = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['payroll.view', 'payroll.run'], 'C2 payroll')), $this->w->c2);
    $this->asToken($c2)->getJson(($this->api)("runs/{$run['id']}/bank-file", $this->w->c2))->assertNotFound();

    // With two-step sign-in and no recent second step: asked for one.
    $this->runnerUser->forceFill(['mfa_enabled_at' => now()])->save();
    $this->asToken($this->runner)->getJson(($this->api)("runs/{$run['id']}/bank-file"))->assertForbidden()->assertJsonPath('code', 'step_up_required');
});

it('shows a portal employee their own approved payslips, linked from their record', function () {
    toggles()->enable($this->w->g1, 'client_portal', 'Test setup');
    $member = User::factory()->create(['email_verified_at' => now()]);
    app(AddMember::class)->handle($this->w->c1, $member, MembershipType::Portal, AccessScope::Own);
    $membership = OrganizationMembership::query()->where('user_id', $member->id)->where('organization_id', $this->w->c1->id)->sole();
    $link = (new PortalLink)->forceFill([
        'organization_id' => $this->w->c1->id, 'membership_id' => $membership->id, 'user_id' => $member->id,
        'subject_type' => 'hrm.employee', 'subject_id' => $this->rahima['id'], 'relation' => 'self', 'status' => PortalLink::ACTIVE, 'linked_via' => 'invitation',
    ]);
    $link->save();
    $portal = orgToken($member, $this->w->c1);

    expect($this->asToken($portal)->getJson('/api/portal/payroll/slips')->assertOk()->json('data'))->toBe([]);
    $run = ($this->approvedRun)();
    $slips = $this->asToken($portal)->getJson('/api/portal/payroll/slips')->assertOk()->json('data');
    expect($slips)->toHaveCount(1)->and($slips[0])->toMatchArray(['employee_name' => 'Rahima Akter', 'period' => '2026-10', 'currency' => 'BDT']);
    $this->asToken($portal)->getJson("/api/portal/payroll/slips/{$slips[0]['id']}")->assertOk()->assertJsonPath('data.lines.0.code', 'BASIC')->assertJsonPath('data.company', $this->w->c1->name);

    $karims = collect($this->asToken($this->runner)->getJson(($this->api)("runs/{$run['id']}"))->json('data.slips'))->firstWhere('employee_name', 'Karim Uddin');
    $this->asToken($portal)->getJson("/api/portal/payroll/slips/{$karims['id']}")->assertNotFound();
    $this->asToken($portal)->getJson(($this->api)("runs/{$run['id']}"))->assertForbidden();

    // The record in the portal links to the payslips and the attendance while both modules are on.
    $pages = collect($this->asToken($portal)->getJson("/api/portal/records/{$link->id}")->assertOk()->json('data.pages'))->pluck('to');
    expect($pages->all())->toContain('/portal/payslips', '/portal/attendance');
    toggles()->disable($this->w->g1, 'payroll', 'Test setup', confirm: true);
    expect(collect($this->asToken($portal)->getJson("/api/portal/records/{$link->id}")->json('data.pages'))->pluck('to')->all())->not->toContain('/portal/payslips');
    $this->asToken($portal)->getJson('/api/portal/payroll/slips')->assertForbidden();
});

it('rings the bell for approvers who have not yet approved, and shows the last month on the dashboard', function () {
    $this->asToken($this->runner)->postJson(($this->api)("employees/{$this->karim['id']}/salary"), [
        'structure_id' => $this->structure['id'], 'basic_minor' => 2000000, 'effective_from' => '2026-10-01',
    ])->assertCreated();
    trustedOrgRule($this->w->c1, 'payroll.salary_approval_levels', 2);
    $run = $this->asToken($this->runner)->postJson(($this->api)('runs'), ['period' => '2026-10'])->json('data');
    $run = ($this->step)(($this->step)($run, 'calculate')->json('data'), 'submit')->assertOk()->json('data');

    $bell = fn (string $token) => collect($this->asToken($token)->getJson('/api/attention')->assertOk()->json('data'))->firstWhere('key', 'payroll.runs_waiting');
    expect($bell($this->approver))->toMatchArray(['count' => 1, 'path' => '/payroll'])
        ->and($bell($this->runner))->toBeNull();
    ($this->step)($run, 'approve', $this->approver)->assertOk();
    expect($bell($this->approver))->toBeNull();

    $widget = fn (string $key) => $this->asToken($this->runner)->getJson("/api/modules/payroll/dashboard/{$key}")->assertOk()->json('data');
    expect($widget('waiting')['value'])->toBe(1)
        ->and($widget('last')['value'])->toBe(0);
});
