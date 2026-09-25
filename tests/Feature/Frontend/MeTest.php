<?php

use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\OrganizationMembership;
use App\Platform\Tenancy\Models\Partner;

/*
 * /api/me: what the browser app needs to start. The `can` flags only shape
 * the UI; the endpoints keep their own checks (covered by the tenancy tests).
 */

beforeEach(function () {
    $this->w = tenancyWorld();
});

it('describes a signed-in user without a context', function () {
    $user = createMember($this->w->c1);
    spaSession($this, $user);

    $response = $this->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.context', null)
        ->assertJsonPath('data.contexts.organizations.0.organization_id', $this->w->c1->id)
        ->assertJsonPath('data.brand.name', config('branding.house.name'))
        ->assertJsonPath('data.locales', ['en', 'bn']);

    expect(array_filter($response->json('data.can')))->toBe([]);
});

it('describes the organization context with its path and settings', function () {
    $user = createMember($this->w->b1);
    spaSession($this, $user, $this->w->b1);

    $response = $this->getJson('/api/me')->assertOk();

    expect($response->json('data.context'))
        ->type->toBe('organization')
        ->id->toBe($this->w->b1->id)
        ->organization_type->toBe('branch')
        ->membership_type->toBe('owner')
        ->and(collect($response->json('data.context.path'))->pluck('id')->all())->toBe([$this->w->g1->id, $this->w->c1->id, $this->w->b1->id])
        ->and($response->json('data.context.settings.currency_code'))->toBe('BDT')
        ->and($response->json('data.context.settings.timezone'))->toBe('Asia/Dhaka');
});

it('gives owners the manage flags and staff without roles none', function (MembershipType $type, bool $can) {
    $user = createMember($this->w->c1, $type);
    spaSession($this, $user, $this->w->c1);

    $me = $this->getJson('/api/me')->json('data');

    expect(array_filter(array_intersect_key($me['can'], array_flip(['organizations.manage', 'modules.manage', 'rules.manage', 'roles.manage', 'members.manage']))))
        ->toHaveCount($can ? 5 : 0)
        ->and($me['can']['partner.rules.manage'])->toBeFalse()
        ->and($me['permissions'] !== [])->toBe($can)
        // Owners do not hold separation-of-duties permissions without a role.
        ->and($me['permissions'])->not->toContain('payroll.approve');
})->with([
    'owner' => [MembershipType::Owner, true],
    'staff' => [MembershipType::Staff, false],
]);

it('drops a context the user may no longer enter', function () {
    $user = createMember($this->w->c1);
    spaSession($this, $user, $this->w->c1);

    OrganizationMembership::where('user_id', $user->id)->update(['status' => MembershipStatus::Suspended]);

    $this->getJson('/api/me')->assertOk()->assertJsonPath('data.context', null);
    expect(session('tenancy_context'))->toBeNull();
});

it('describes a partner console context', function (PartnerUserRole $role, bool $can) {
    $staff = createPartnerStaff($this->w->partnerA, $role);
    spaSession($this, $staff, partner: $this->w->partnerA);

    $response = $this->getJson('/api/me')
        ->assertJsonPath('data.context.type', 'partner')
        ->assertJsonPath('data.context.id', $this->w->partnerA->id)
        ->assertJsonPath('data.context.role', $role->value);

    expect($response->json('data.can'))->toBe([
        'rules.manage' => false,
        'partner.rules.manage' => $can,
    ])->and($response->json('data.permissions'))->toBe([]);
})->with([
    'owner' => [PartnerUserRole::Owner, true],
    'support' => [PartnerUserRole::Support, false],
]);

it('uses a white-label partner brand and rejects unsafe values', function () {
    $partner = Partner::factory()->create([
        'name' => 'Acme ERP',
        'settings' => ['brand' => ['primary_color' => 'red;}</style><script>', 'support_email' => 'not-an-email']],
    ]);
    $group = createGroup($partner, 'Acme Group');
    $user = createMember($group);
    spaSession($this, $user, $group);

    $this->getJson('/api/me')
        ->assertJsonPath('data.brand.name', 'Acme ERP')
        ->assertJsonPath('data.brand.primary_color', strtoupper(config('branding.house.primary_color')))
        ->assertJsonPath('data.brand.support_email', null);
});

it('uses a valid partner brand color', function () {
    $partner = Partner::factory()->create(['settings' => ['brand' => ['name' => 'Shikkha Pro', 'primary_color' => '#0f766e']]]);
    $group = createGroup($partner);
    $user = createMember($group);
    spaSession($this, $user, $group);

    $this->getJson('/api/me')
        ->assertJsonPath('data.brand.name', 'Shikkha Pro')
        ->assertJsonPath('data.brand.primary_color', '#0F766E');
});
