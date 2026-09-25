<?php

use App\Platform\Access\Models\MembershipRole;
use App\Platform\Access\Models\Role;
use App\Platform\Audit\AuditLog;
use App\Platform\Tenancy\Enums\MembershipType;

beforeEach(function () {
    $this->w = tenancyWorld();
    toggles()->enable($this->w->g1, 'payroll', 'Test setup');

    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->roles = "/api/organizations/{$this->w->c1->id}/roles";
});

it('creates a role with translated names and audits it', function () {
    $response = $this->asToken($this->token)->postJson($this->roles, [
        'name' => ['en' => 'Branch manager', 'bn' => 'শাখা ব্যবস্থাপক'],
        'description' => ['en' => 'Runs one branch'],
        'permissions' => ['payroll.view', 'hrm.view', 'members.manage'],
        'reason' => 'New branches opening',
    ])->assertCreated()
        ->assertJsonPath('data.key', 'branch_manager')
        ->assertJsonPath('data.name', 'Branch manager')
        ->assertJsonPath('data.names.bn', 'শাখা ব্যবস্থাপক')
        ->assertJsonPath('data.permissions', ['hrm.view', 'members.manage', 'payroll.view'])
        ->assertJsonPath('data.owned_here', true)
        ->assertJsonPath('data.editable', true)
        ->assertJsonPath('data.version', 1);

    $audit = AuditLog::where('action', 'role.created')->sole();
    expect($audit->target_id)->toBe($response->json('data.id'))
        ->and($audit->organization_id)->toBe($this->w->c1->id)
        ->and($audit->partner_id)->toBe($this->w->partnerA->id)
        ->and($audit->reason)->toBe('New branches opening');
});

it('answers in Bangla', function () {
    $this->asToken($this->token)->withHeader('X-Locale', 'bn')->postJson($this->roles, [
        'name' => ['bn' => 'শুধু বাংলা নাম'],
        'permissions' => [],
    ])->assertCreated()
        ->assertJsonPath('data.name', 'শুধু বাংলা নাম')
        ->assertJsonPath('data.key', 'role');

    $this->asToken($this->token)->withHeader('X-Locale', 'bn')->postJson($this->roles, [
        'name' => ['en' => 'Pair'],
        'permissions' => ['payroll.run', 'payroll.approve'],
    ])->assertUnprocessable()->assertJsonPath('message', fn (string $message) => str_contains($message, 'একজন মানুষ'));
});

it('validates role input', function (array $body, string $field) {
    $this->asToken($this->token)->postJson($this->roles, $body)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unknown field' => [['name' => ['en' => 'X'], 'permissions' => [], 'organization_id' => 'x'], 'organization_id'],
    'unknown permission' => [['name' => ['en' => 'X'], 'permissions' => ['payroll.fly']], 'permissions.0'],
    'no name' => [['name' => ['en' => ' '], 'permissions' => []], 'name'],
    'unsupported locale' => [['name' => ['fr' => 'Rôle'], 'permissions' => []], 'name'],
    'neither permissions nor template' => [['name' => ['en' => 'X']], 'permissions'],
    'duplicate permission' => [['name' => ['en' => 'X'], 'permissions' => ['hrm.view', 'hrm.view']], 'permissions.0'],
]);

it('refuses permissions of a module that is off here', function () {
    $this->asToken($this->token)->postJson($this->roles, ['name' => ['en' => 'Stock'], 'permissions' => ['inventory.view']])
        ->assertUnprocessable()->assertJsonPath('code', 'module_disabled');
});

it('lists sector templates and clones one', function () {
    $templates = collect($this->asToken($this->token)->getJson("/api/organizations/{$this->w->c1->id}/role-templates")->assertOk()->json('data'));

    // C1 is a school: every-sector templates plus the school ones.
    expect($templates->pluck('key')->all())->toContain('administrator', 'accountant', 'principal', 'teacher')
        ->and($templates->firstWhere('key', 'teacher')['name'])->toBe('Teacher')
        ->and($templates->firstWhere('key', 'accountant')['permissions'])->toContain('payroll.run')->not->toContain('payroll.approve');

    $this->asToken($this->token)->postJson($this->roles, ['name' => ['en' => 'Teacher'], 'template_key' => 'teacher'])
        ->assertCreated()
        ->assertJsonPath('data.template_key', 'teacher')
        // attendance is on (payroll needs it); hrm.view too.
        ->assertJsonPath('data.permissions', ['attendance.manage', 'attendance.view', 'hrm.view']);

    $this->asToken($this->token)->postJson($this->roles, ['name' => ['en' => 'X'], 'template_key' => 'factory_foreman'])
        ->assertUnprocessable()->assertJsonPath('code', 'template_not_found');
});

it('hides templates of other sectors', function () {
    $this->w->c3->forceFill(['sector_key' => 'retail'])->save();
    $token = orgToken(createMember($this->w->c3), $this->w->c3);

    $keys = collect($this->asToken($token)->getJson("/api/organizations/{$this->w->c3->id}/role-templates")->json('data'))->pluck('key');

    expect($keys)->toContain('manager')->not->toContain('principal');
});

it('lists own and inherited roles with where they come from', function () {
    $companyRole = makeRole($this->w->c1, ['hrm.view'], 'Company staff');
    $branchRole = makeRole($this->w->b1, ['hrm.view'], 'Branch staff');
    makeRole($this->w->c2, ['hrm.view'], 'Other company');
    staffWithRoles($this->w->b1, $companyRole);

    $roles = collect($this->asToken($this->token)->getJson("/api/organizations/{$this->w->b1->id}/roles")->assertOk()->json('data'))->keyBy('id');

    expect($roles->keys()->sort()->values()->all())->toBe(collect([$companyRole->id, $branchRole->id])->sort()->values()->all())
        ->and($roles[$companyRole->id]['owned_here'])->toBeFalse()
        ->and($roles[$companyRole->id]['organization']['name'])->toBe('C1')
        ->and($roles[$companyRole->id]['members_count'])->toBe(1)
        ->and($roles[$branchRole->id]['owned_here'])->toBeTrue();
});

it('changes an inherited role only where it is owned', function () {
    $companyRole = makeRole($this->w->c1, ['hrm.view'], 'Company staff');

    $this->asToken($this->token)->patchJson("/api/organizations/{$this->w->b1->id}/roles/{$companyRole->id}", [
        'base_version' => 1, 'permissions' => [], 'reason' => 'Clean up',
    ])->assertNotFound()->assertJsonPath('code', 'role_not_found');
});

it('updates with optimistic locking so nobody loses work', function () {
    $role = makeRole($this->w->c1, ['hrm.view'], 'Clerk');
    $url = "{$this->roles}/{$role->id}";

    $this->asToken($this->token)->patchJson($url, ['base_version' => 1, 'permissions' => ['hrm.view', 'payroll.view'], 'reason' => 'Clerks see payroll'])
        ->assertOk()->assertJsonPath('data.version', 2);

    // A second editor who started from version 1.
    $this->asToken($this->token)->patchJson($url, ['base_version' => 1, 'name' => ['en' => 'Senior clerk'], 'reason' => 'Rename the role'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'version_conflict')
        ->assertJsonPath('current_version', 2);

    expect($role->fresh()->displayName())->toBe('Clerk');

    $audit = AuditLog::where('action', 'role.updated')->sole();
    expect($audit->old_values)->toBe(['permissions' => ['hrm.view']])
        ->and($audit->new_values)->toBe(['permissions' => ['hrm.view', 'payroll.view']])
        ->and($audit->reason)->toBe('Clerks see payroll');
});

it('needs a reason for changes', function () {
    $role = makeRole($this->w->c1, ['hrm.view'], 'Clerk');

    $this->asToken($this->token)->patchJson("{$this->roles}/{$role->id}", ['base_version' => 1, 'permissions' => []])
        ->assertJsonValidationErrors('reason');
    $this->asToken($this->token)->deleteJson("{$this->roles}/{$role->id}")
        ->assertJsonValidationErrors('reason');
});

it('refuses to delete a role someone holds, then deletes it once free', function () {
    $role = makeRole($this->w->c1, ['hrm.view'], 'Clerk');
    $membership = giveRoles(staffWithRoles($this->w->b1), $this->w->b1, $role);

    $this->asToken($this->token)->deleteJson("{$this->roles}/{$role->id}", ['reason' => 'No longer needed'])
        ->assertUnprocessable()->assertJsonPath('code', 'role_in_use')->assertJsonPath('members', 1);

    $this->asToken($this->token)->putJson("/api/organizations/{$this->w->b1->id}/members/{$membership->id}/roles", ['role_ids' => [], 'reason' => 'Moved to sales'])
        ->assertOk()->assertJsonPath('data.roles', []);

    $this->asToken($this->token)->deleteJson("{$this->roles}/{$role->id}", ['reason' => 'No longer needed'])->assertOk();

    expect(Role::find($role->id))->toBeNull()
        ->and(AuditLog::where('action', 'role.deleted')->where('target_id', $role->id)->exists())->toBeTrue();
});

it('gives roles to a member and audits the change', function () {
    $clerk = makeRole($this->w->c1, ['hrm.view'], 'Clerk');
    $branchRole = makeRole($this->w->b1, ['payroll.view'], 'Branch payroll');
    $user = createMember($this->w->b1, MembershipType::Staff);
    $membership = giveRoles($user, $this->w->b1);

    $this->asToken($this->token)->putJson("/api/organizations/{$this->w->b1->id}/members/{$membership->id}/roles", [
        'role_ids' => [$clerk->id, $branchRole->id],
        'reason' => 'Joined the branch office',
    ])->assertOk()->assertJsonCount(2, 'data.roles');

    $this->asToken($this->token)->getJson("/api/organizations/{$this->w->b1->id}/members")
        ->assertOk()->assertJsonCount(2, 'data.0.roles');

    $audit = AuditLog::where('action', 'membership.roles_changed')->sole();
    expect($audit->old_values)->toBe(['roles' => []])
        ->and($audit->new_values['roles'])->toHaveCount(2)
        ->and($audit->reason)->toBe('Joined the branch office');

    // A role of a unit below cannot be given above it.
    $companyMember = giveRoles(createMember($this->w->c1, MembershipType::Staff), $this->w->c1);
    $this->asToken($this->token)->putJson("/api/organizations/{$this->w->c1->id}/members/{$companyMember->id}/roles", [
        'role_ids' => [$branchRole->id], 'reason' => 'Try a branch role',
    ])->assertUnprocessable()->assertJsonPath('code', 'role_not_usable');
});

it('explains which permissions this person may grant', function () {
    $hr = makeRole($this->w->c1, ['members.manage', 'roles.manage', 'hrm.view'], 'HR');
    $token = orgToken(staffWithRoles($this->w->c1, $hr), $this->w->c1);

    $response = $this->asToken($token)->getJson("/api/organizations/{$this->w->c1->id}/permissions")->assertOk();
    $permissions = collect($response->json('data'))->flatMap(fn ($group) => $group['permissions'])->keyBy('key');
    $groups = collect($response->json('data'))->keyBy('group');

    expect($permissions['hrm.view']['blocked_by'])->toBeNull()
        ->and($permissions['payroll.run']['blocked_by'])->toBe('not_held')
        ->and($permissions['inventory.view']['blocked_by'])->toBe('module_disabled')
        ->and($permissions['members.manage']['label'])->toBe('Add members and give roles')
        ->and($permissions['rules.edit.payroll']['label'])->toBe('Edit Payroll settings')
        ->and($groups['inventory']['module_enabled'])->toBeFalse()
        ->and($groups['people']['label'])->toBe('People and roles')
        ->and($response->json('separation_of_duties'))->toContain(['first' => 'payroll.run', 'second' => 'payroll.approve']);
});

// ── Isolation ──

it('never shows or touches roles of another company or partner', function () {
    $c2Role = makeRole($this->w->c2, ['hrm.view'], 'C2 role');
    $c4Role = makeRole($this->w->c4, ['hrm.view'], 'Partner B role');

    foreach ([$this->w->c2, $this->w->c4] as $foreign) {
        $this->asToken($this->token)->getJson("/api/organizations/{$foreign->id}/roles")->assertNotFound();
        $this->asToken($this->token)->postJson("/api/organizations/{$foreign->id}/roles", ['name' => ['en' => 'X'], 'permissions' => []])->assertNotFound();
    }

    foreach ([$c2Role, $c4Role] as $role) {
        $this->asToken($this->token)->getJson("{$this->roles}/{$role->id}")->assertNotFound();
        $this->asToken($this->token)->patchJson("{$this->roles}/{$role->id}", ['base_version' => 1, 'permissions' => [], 'reason' => 'Take over'])->assertNotFound();
        $this->asToken($this->token)->deleteJson("{$this->roles}/{$role->id}", ['reason' => 'Take over'])->assertNotFound();
    }

    // Nor can a foreign role be given to a member here.
    $membership = giveRoles(createMember($this->w->c1, MembershipType::Staff), $this->w->c1);
    $this->asToken($this->token)->putJson("/api/organizations/{$this->w->c1->id}/members/{$membership->id}/roles", [
        'role_ids' => [$c4Role->id], 'reason' => 'Cross-partner attempt',
    ])->assertUnprocessable()->assertJsonPath('code', 'role_not_usable');

    expect(MembershipRole::where('role_id', $c4Role->id)->exists())->toBeFalse();
});

it('keeps the organization id out of the request body', function () {
    $this->asToken($this->token)->postJson($this->roles, [
        'name' => ['en' => 'X'], 'permissions' => [], 'organization_id' => $this->w->c4->id,
    ])->assertUnprocessable();

    expect(Role::where('organization_id', $this->w->c4->id)->exists())->toBeFalse();
});
