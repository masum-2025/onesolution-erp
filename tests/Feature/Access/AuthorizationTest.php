<?php

use App\Platform\Access\AccessResolver;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationType;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/*
 * Phase 4 acceptance: permission + reach + module enabled on every check,
 * no escalation, separation of duties, and API-level proof (never only a
 * hidden button).
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    toggles()->enable($this->w->g1, 'payroll', 'Test setup');
    toggles()->enable($this->w->g1, 'accounting', 'Test setup');

    // A module endpoint protected the way module routes will be.
    Route::middleware(['api', 'auth:sanctum', 'org', 'can:payroll.run'])
        ->get('/api/test/payroll-run', fn () => response()->json(['ok' => true]));
});

function companyOwnerToken(object $w): string
{
    return orgToken(createMember($w->c1), $w->c1);
}

// ── Acceptance 1: a branch admin cannot grant beyond what the company gave ──

it('stops a branch admin from putting permissions they lack into a role', function () {
    $branchAdmin = makeRole($this->w->c1, ['roles.manage', 'members.manage', 'hrm.view'], 'Branch admin');
    $token = orgToken(staffWithRoles($this->w->b1, $branchAdmin), $this->w->b1);

    $this->asToken($token)->postJson("/api/organizations/{$this->w->b1->id}/roles", [
        'name' => ['en' => 'Payroll clerk'],
        'permissions' => ['hrm.view', 'payroll.view'],
    ])->assertForbidden()
        ->assertJsonPath('code', 'cannot_grant')
        ->assertJsonPath('permissions', ['payroll.view']);

    $this->asToken($token)->postJson("/api/organizations/{$this->w->b1->id}/roles", [
        'name' => ['en' => 'HR viewer'],
        'permissions' => ['hrm.view'],
    ])->assertCreated()->assertJsonPath('data.permissions', ['hrm.view']);
});

it('stops a branch admin from giving a company role with more than they hold', function () {
    $branchAdmin = makeRole($this->w->c1, ['members.manage', 'hrm.view'], 'Branch admin');
    $payrollRole = makeRole($this->w->c1, ['payroll.view', 'payroll.run'], 'Payroll');
    $token = orgToken(staffWithRoles($this->w->b1, $branchAdmin), $this->w->b1);
    $clerk = createMember($this->w->b1, MembershipType::Staff);
    $membership = giveRoles($clerk, $this->w->b1);

    $this->asToken($token)->putJson("/api/organizations/{$this->w->b1->id}/members/{$membership->id}/roles", [
        'role_ids' => [$payrollRole->id],
        'reason' => 'New payroll clerk',
    ])->assertForbidden()->assertJsonPath('code', 'cannot_grant');

    expect($membership->roles()->count())->toBe(0);
});

it('keeps a branch admin inside their branch', function () {
    $branchAdmin = makeRole($this->w->c1, ['organizations.manage', 'roles.manage', 'members.manage', 'modules.manage'], 'Branch admin');
    $companyRole = makeRole($this->w->c1, ['hrm.view'], 'Company staff');
    $token = orgToken(staffWithRoles($this->w->b1, $branchAdmin), $this->w->b1);

    // Visible (read), but not theirs to change.
    $this->asToken($token)->getJson("/api/organizations/{$this->w->c1->id}")->assertOk();
    $this->asToken($token)->patchJson("/api/organizations/{$this->w->c1->id}", ['name' => ['en' => 'Taken']])->assertForbidden();
    $this->asToken($token)->postJson("/api/organizations/{$this->w->c1->id}/modules/crm/enable", ['reason' => 'Try it out'])->assertForbidden();
    $this->asToken($token)->patchJson("/api/organizations/{$this->w->c1->id}/roles/{$companyRole->id}", [
        'base_version' => 1, 'permissions' => [], 'reason' => 'Clean up',
    ])->assertForbidden();
    $this->asToken($token)->getJson("/api/organizations/{$this->w->c2->id}/members")->assertNotFound();

    // Their own branch works.
    $this->asToken($token)->patchJson("/api/organizations/{$this->w->b1->id}", ['name' => ['en' => 'Branch One']])->assertOk();
});

// ── Acceptance 2: hiding a button is never the only protection ──

it('refuses every change to a staff member without roles', function () {
    $token = orgToken(createMember($this->w->c1, MembershipType::Staff), $this->w->c1);
    $c1 = "/api/organizations/{$this->w->c1->id}";

    $calls = [
        ['post', '/api/organizations', ['type' => 'branch', 'parent_id' => $this->w->c1->id, 'name' => ['en' => 'X']]],
        ['patch', $c1, ['name' => ['en' => 'X']]],
        ['post', "/api/organizations/{$this->w->b1->id}/move", ['new_parent_id' => $this->w->c1->id, 'reason' => 'Reorganize']],
        ['get', "{$c1}/members", []],
        ['post', "{$c1}/members", ['email' => 'x@example.com', 'membership_type' => 'staff']],
        ['post', "{$c1}/modules/crm/enable", ['reason' => 'Try it out']],
        ['put', "{$c1}/rules/attendance.late_grace_minutes", ['mode' => 'set', 'value' => 5, 'reason' => 'Late buses']],
        ['get', "{$c1}/rule-approvals", []],
        ['get', "{$c1}/roles", []],
        ['get', "{$c1}/permissions", []],
        ['post', "{$c1}/roles", ['name' => ['en' => 'X'], 'permissions' => []]],
        ['get', '/api/test/payroll-run', []],
    ];

    foreach ($calls as [$method, $uri, $body]) {
        $response = $this->asToken($token)->json($method, $uri, $body);
        expect($response->status())->toBe(403, "{$method} {$uri}: ".$response->content());
    }
});

it('lets a role do exactly what it names', function () {
    $hr = makeRole($this->w->c1, ['members.manage'], 'HR');
    $token = orgToken(staffWithRoles($this->w->c1, $hr), $this->w->c1);
    $c1 = "/api/organizations/{$this->w->c1->id}";

    $this->asToken($token)->getJson("{$c1}/members")->assertOk();
    $this->asToken($token)->patchJson($c1, ['name' => ['en' => 'X']])->assertForbidden();
    $this->asToken($token)->postJson("{$c1}/modules/crm/enable", ['reason' => 'Try it out'])->assertForbidden();
});

// ── Module disabled: 403 even with the role ──

it('refuses a module permission while the module is off, even with the role', function () {
    $payroll = makeRole($this->w->c1, ['payroll.view', 'payroll.run'], 'Payroll');
    $user = staffWithRoles($this->w->c1, $payroll);

    $this->asToken(orgToken($user, $this->w->c1))->getJson('/api/test/payroll-run')->assertOk();

    toggles()->disable($this->w->c1, 'payroll', 'Paused');

    $this->asToken(orgToken($user, $this->w->c1))->getJson('/api/test/payroll-run')->assertForbidden();

    actInOrganization($user, $this->w->c1);
    expect(app(AccessResolver::class)->holds('payroll.run'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('payroll.run', $this->w->c1))->toBeFalse()
        ->and(app(AccessResolver::class)->effective())->not->toContain('payroll.run');
});

// ── Acceptance 3: separation of duties ──

it('refuses a role that holds both sides of a pair', function () {
    $this->asToken(companyOwnerToken($this->w))->postJson("/api/organizations/{$this->w->c1->id}/roles", [
        'name' => ['en' => 'Payroll all-in-one'],
        'permissions' => ['payroll.run', 'payroll.approve'],
    ])->assertUnprocessable()
        ->assertJsonPath('code', 'separation_of_duties')
        ->assertJsonPath('pairs.0', ['first' => 'payroll.run', 'second' => 'payroll.approve']);
});

it('refuses giving one person roles that together break a pair', function () {
    $maker = makeRole($this->w->c1, ['payroll.run'], 'Maker');
    $checker = makeRole($this->w->c1, ['payroll.approve'], 'Checker');
    $person = createMember($this->w->c1, MembershipType::Staff);
    $membership = giveRoles($person, $this->w->c1, $maker);

    $this->asToken(companyOwnerToken($this->w))->putJson("/api/organizations/{$this->w->c1->id}/members/{$membership->id}/roles", [
        'role_ids' => [$maker->id, $checker->id],
        'reason' => 'Cover for leave',
    ])->assertUnprocessable()->assertJsonPath('code', 'separation_of_duties');

    expect($membership->roles()->pluck('roles.id')->all())->toBe([$maker->id]);
});

it('refuses a role change that would break a pair for its holders', function () {
    $maker = makeRole($this->w->c1, ['payroll.run'], 'Maker');
    $viewer = makeRole($this->w->c1, ['payroll.view'], 'Viewer');
    staffWithRoles($this->w->c1, $maker, $viewer);

    $this->asToken(companyOwnerToken($this->w))->patchJson("/api/organizations/{$this->w->c1->id}/roles/{$viewer->id}", [
        'base_version' => 1,
        'permissions' => ['payroll.view', 'payroll.approve'],
        'reason' => 'Viewers approve now',
    ])->assertUnprocessable()
        ->assertJsonPath('code', 'separation_of_duties_members')
        ->assertJsonPath('members', 1);
});

it('uses company-added pairs and fails closed for people who already hold both', function () {
    $poster = makeRole($this->w->c1, ['hrm.manage'], 'HR');
    $runner = makeRole($this->w->c1, ['payroll.run'], 'Payroll');
    $user = staffWithRoles($this->w->c1, $poster, $runner);

    orgRule($this->w->c1, 'access.separation_of_duties', [
        ['first' => 'payroll.run', 'second' => 'payroll.approve'],
        ['first' => 'hrm.manage', 'second' => 'payroll.run'],
    ]);
    // Sensitive rule: a second person approves it.
    $value = App\Platform\Rules\Models\RuleValue::where('rule_key', 'access.separation_of_duties')->latest('created_at')->first();
    ruleService()->approve($value, createMember($this->w->g1));

    actInOrganization($user, $this->w->c1);
    expect(app(AccessResolver::class)->held())->not->toContain('hrm.manage')->not->toContain('payroll.run');
});

it('does not give owners both sides of a pair without a role', function () {
    $owner = createMember($this->w->c1);
    actInOrganization($owner, $this->w->c1);
    $access = app(AccessResolver::class);

    expect($access->holds('payroll.run'))->toBeFalse()
        ->and($access->holds('payroll.approve'))->toBeFalse()
        ->and($access->holds('payroll.view'))->toBeTrue()
        ->and($access->canGrant('payroll.approve', $this->w->c1))->toBeTrue();

    app(CurrentContext::class)->clear();
    $access->forget();
    giveRoles($owner, $this->w->c1, makeRole($this->w->c1, ['payroll.approve'], 'Checker'));
    actInOrganization($owner, $this->w->c1);

    expect(app(AccessResolver::class)->holds('payroll.approve'))->toBeTrue();
});

// ── Ownership, self-change, portal users ──

it('lets only owners make someone an owner', function () {
    $hr = makeRole($this->w->c1, ['members.manage'], 'HR');
    $token = orgToken(staffWithRoles($this->w->c1, $hr), $this->w->c1);
    $newcomer = App\Models\User::factory()->create();
    $owner = createMember($this->w->c1);
    $ownerMembership = giveRoles($owner, $this->w->c1);

    $this->asToken($token)->postJson("/api/organizations/{$this->w->c1->id}/members", ['email' => $newcomer->email, 'membership_type' => 'owner'])
        ->assertForbidden();
    $this->asToken($token)->patchJson("/api/organizations/{$this->w->c1->id}/members/{$ownerMembership->id}", ['status' => 'suspended'])
        ->assertForbidden();
    $this->asToken($token)->postJson("/api/organizations/{$this->w->c1->id}/members", ['email' => $newcomer->email, 'membership_type' => 'staff'])
        ->assertCreated();

    $this->asToken(orgToken($owner, $this->w->c1))->postJson("/api/organizations/{$this->w->c1->id}/members", [
        'email' => App\Models\User::factory()->create()->email, 'membership_type' => 'owner',
    ])->assertCreated();
});

it('does not let anyone change their own roles', function () {
    $admin = makeRole($this->w->c1, ['members.manage', 'payroll.view'], 'Admin');
    $user = staffWithRoles($this->w->c1, $admin);
    $membership = giveRoles($user, $this->w->c1);

    $this->asToken(orgToken($user, $this->w->c1))->putJson("/api/organizations/{$this->w->c1->id}/members/{$membership->id}/roles", [
        'role_ids' => [], 'reason' => 'Clean up my roles',
    ])->assertUnprocessable()->assertJsonPath('code', 'own_roles');
});

it('keeps portal users out of the structure and staff roles', function () {
    $parent = createMember($this->w->c1, MembershipType::Portal);
    $token = orgToken($parent, $this->w->c1);

    $this->asToken($token)->getJson('/api/organizations')->assertForbidden();
    $this->asToken($token)->getJson("/api/organizations/{$this->w->c1->id}")->assertForbidden();
    $this->asToken($token)->getJson("/api/organizations/{$this->w->c1->id}/modules")->assertForbidden();
    $this->asToken($token)->getJson("/api/organizations/{$this->w->c1->id}/rules")->assertForbidden();
    $this->asToken($token)->getJson('/api/menu')->assertOk()->assertJsonPath('data', []);

    $membership = giveRoles($parent, $this->w->c1);
    $this->asToken(companyOwnerToken($this->w))->putJson("/api/organizations/{$this->w->c1->id}/members/{$membership->id}/roles", [
        'role_ids' => [makeRole($this->w->c1, ['hrm.view'], 'Viewer')->id], 'reason' => 'By mistake',
    ])->assertUnprocessable()->assertJsonPath('code', 'portal_membership');
});

// ── Rules use their own permissions ──

it('edits only the rules a role names and approves with rules.approve', function () {
    $payrollEditor = makeRole($this->w->c1, ['rules.edit.payroll'], 'Payroll settings');
    $editor = staffWithRoles($this->w->c1, $payrollEditor);
    $token = orgToken($editor, $this->w->c1);
    $rules = "/api/organizations/{$this->w->c1->id}/rules";

    $this->asToken($token)->putJson("{$rules}/payroll.overtime_multiplier", ['mode' => 'set', 'value' => '2', 'reason' => 'New contract', 'country_code' => 'BD'])
        ->assertSuccessful();
    $this->asToken($token)->putJson("{$rules}/attendance.late_grace_minutes", ['mode' => 'set', 'value' => 5, 'reason' => 'Late buses'])
        ->assertForbidden();

    $listed = collect($this->asToken($token)->getJson($rules)->assertOk()->json('data'))
        ->flatMap(fn ($group) => collect($group['categories'])->flatMap(fn ($category) => $category['rules']))
        ->keyBy('key');
    expect($listed['attendance.late_grace_minutes']['edit_blocked_by'])->toBe('no_permission')
        ->and($listed['payroll.overtime_multiplier']['editable'])->toBeTrue();

    // A sensitive change waits for someone with rules.approve.
    $this->asToken(companyOwnerToken($this->w))->putJson("{$rules}/payroll.salary_approval_levels", ['mode' => 'set', 'value' => 3, 'reason' => 'Board decision'])
        ->assertStatus(202);
    $pendingId = $this->asToken(companyOwnerToken($this->w))->getJson("/api/organizations/{$this->w->c1->id}/rule-approvals")->json('data.0.id');

    $this->asToken($token)->postJson("/api/organizations/{$this->w->c1->id}/rule-approvals/{$pendingId}/approve")->assertForbidden();

    $approver = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['rules.approve'], 'Approver'));
    $this->asToken(orgToken($approver, $this->w->c1))->postJson("/api/organizations/{$this->w->c1->id}/rule-approvals/{$pendingId}/approve")
        ->assertOk();
});

it('tells the app what the member may do', function () {
    $role = makeRole($this->w->c1, ['hrm.view', 'rules.edit.hrm', 'inventory.view'], 'HR viewer');
    $user = staffWithRoles($this->w->c1, $role);
    spaSession($this, $user, $this->w->c1);

    $me = $this->getJson('/api/me')->assertOk()->json('data');

    // inventory is off here, so inventory.view is not listed.
    expect($me['permissions'])->toBe(['hrm.view', 'rules.edit.hrm'])
        ->and($me['can']['rules.manage'])->toBeTrue()
        ->and($me['can'])->not->toHaveKey('members.manage')
        ->and($me['context']['roles'])->toBe([['id' => $role->id, 'name' => 'HR viewer']]);
});

it('only counts roles owned by the member organization or above', function () {
    // A role of a sibling branch sneaked in at the database level gives nothing.
    $b1b = createChild($this->w->c1, OrganizationType::Branch, 'B1b');
    $sibling = makeRole($b1b, ['hrm.view'], 'Sibling');
    $user = staffWithRoles($this->w->b1, $sibling);

    actInOrganization($user, $this->w->b1);
    expect(app(AccessResolver::class)->held())->toBe([]);
});

it('does not let a group-level role pair rule be weakened without approval', function () {
    $row = orgRule($this->w->c1, 'access.separation_of_duties', [], RuleMode::Set, createMember($this->w->c1));

    expect($row->status->value)->toBe('pending_approval');
});
