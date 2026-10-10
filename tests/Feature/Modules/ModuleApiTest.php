<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->owner = createMember($this->w->c1, MembershipType::Owner);
    $this->token = orgToken($this->owner, $this->w->c1);
});

it('lists every module with its state and where it comes from', function () {
    toggles()->enable($this->w->c1, 'crm', 'Sales team starts');

    $response = $this->asToken($this->token)->getJson("/api/organizations/{$this->w->b1->id}/modules")->assertOk();
    $crm = collect($response->json('data'))->firstWhere('key', 'crm');

    expect($response->json('data'))->toHaveCount(24)
        ->and($crm['enabled'])->toBeTrue()
        ->and($crm['source'])->toBe('inherited')
        ->and($crm['source_organization'])->toBe(['id' => $this->w->c1->id, 'name' => 'C1'])
        ->and($crm['name'])->toBe('Customer Relations');
});

it('explains why a module is off', function () {
    $response = $this->asToken($this->token)->getJson("/api/organizations/{$this->w->c1->id}/modules")->assertOk();
    $reports = collect($response->json('data'))->firstWhere('key', 'custom_reports');

    expect($reports['reason'])->toBe('not_in_plan')
        ->and($reports['reason_message'])->toBe('Not included in the current plan');
});

it('enables payroll and reports the dependencies it switched on', function () {
    $this->asToken($this->token)->postJson("/api/organizations/{$this->w->c1->id}/modules/payroll/enable", [
        'reason' => 'Start running payroll',
    ])->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.auto_enabled', ['hrm', 'attendance']);

    expect(AuditLog::where('action', 'module.enabled')->where('actor_user_id', $this->owner->id)->count())->toBe(3);
});

it('returns 409 with the affected modules before disabling dependents', function () {
    toggles()->enable($this->w->c1, 'payroll', 'Start running payroll');

    $this->asToken($this->token)->postJson("/api/organizations/{$this->w->c1->id}/modules/hrm/disable", [
        'reason' => 'Stop HR for now',
    ])->assertStatus(409)
        ->assertJsonPath('code', 'dependents_need_confirmation')
        ->assertJsonPath('dependents', ['attendance', 'payroll']);

    $this->asToken($this->token)->postJson("/api/organizations/{$this->w->c1->id}/modules/hrm/disable", [
        'reason' => 'Stop HR for now',
        'confirm' => true,
    ])->assertOk()->assertJsonPath('data.also_disabled', ['attendance', 'payroll']);
});

it('rejects changes a parent has locked, naming who locked it', function () {
    toggles()->disable($this->w->g1, 'crm', 'Group policy: no CRM', lock: true);

    $this->asToken($this->token)->postJson("/api/organizations/{$this->w->c1->id}/modules/crm/enable", [
        'reason' => 'We want CRM',
    ])->assertUnprocessable()
        ->assertJsonPath('code', 'locked_by_parent')
        ->assertJsonPath('message', 'Customer Relations is locked by G1. Ask them to change it.');
});

it('lets a group owner lock a module for the whole group', function () {
    $groupOwner = createMember($this->w->g1, MembershipType::Owner, AccessScope::Descendants);

    $this->asToken(orgToken($groupOwner, $this->w->g1))->postJson("/api/organizations/{$this->w->g1->id}/modules/crm/disable", [
        'reason' => 'Group policy: no CRM',
        'lock' => true,
    ])->assertOk()->assertJsonPath('data.locked_here', true);

    expect(resolvedModule('crm', $this->w->c2)->lockedByOrganizationId)->toBe($this->w->g1->id);
});

it('forbids non-owners from changing modules', function () {
    $staff = createMember($this->w->c1, MembershipType::Staff);
    $token = orgToken($staff, $this->w->c1);

    $this->asToken($token)->getJson("/api/organizations/{$this->w->c1->id}/modules")->assertOk();
    $this->asToken($token)->postJson("/api/organizations/{$this->w->c1->id}/modules/crm/enable", [
        'reason' => 'I want CRM',
    ])->assertForbidden();
});

it('cannot touch modules of an organization outside the context', function (string $key) {
    $this->asToken($this->token)->getJson("/api/organizations/{$this->w->{$key}->id}/modules")->assertNotFound();
    $this->asToken($this->token)->postJson("/api/organizations/{$this->w->{$key}->id}/modules/crm/enable", [
        'reason' => 'Reaching across',
    ])->assertNotFound();

    expect(resolvedModule('crm', $this->w->{$key})->enabled)->toBeFalse();
})->with(['g1', 'c2', 'c4']);

it('answers 404 for an unknown module', function () {
    $this->asToken($this->token)->postJson("/api/organizations/{$this->w->c1->id}/modules/teleport/enable", [
        'reason' => 'Beam me up',
    ])->assertNotFound()->assertJsonPath('code', 'module_not_found');
});

it('validates toggle input', function (array $payload, string $field) {
    $this->asToken($this->token)->postJson("/api/organizations/{$this->w->c1->id}/modules/crm/enable", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing reason' => [[], 'reason'],
    'short reason' => [['reason' => 'no'], 'reason'],
    'unknown field' => [['reason' => 'Valid reason', 'organization_id' => 'x'], 'organization_id'],
]);

it('shows only enabled modules in the menu', function () {
    toggles()->enable($this->w->c1, 'payroll', 'Start running payroll');

    $menu = $this->asToken($this->token)->getJson('/api/menu')->assertOk()->json('data');

    expect(array_column($menu, 'module'))->toBe(['hrm', 'attendance', 'payroll'])
        ->and($menu[2]['label'])->toBe('Payroll');
});

it('translates the menu for Bangla organizations', function () {
    $this->w->g1->update(['default_locale' => 'bn']);
    toggles()->enable($this->w->c1, 'hrm', 'Start HR');

    $this->asToken(orgToken(withoutOwnLanguage($this->owner), $this->w->c1))->getJson('/api/menu')
        ->assertOk()
        ->assertJsonPath('data.0.label', 'মানবসম্পদ');
});
