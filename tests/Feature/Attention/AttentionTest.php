<?php

use App\Models\User;
use App\Platform\Modules\ManifestLoader;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\SupportAccess\Enums\GrantStatus;
use App\Platform\SupportAccess\Models\SupportGrant;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Organization;
use Tests\Fixtures\FixtureExpiringDocuments;

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->owner = createMember($this->w->c1, MembershipType::Owner);
});

function pendingGrant(object $w, Organization $organization): SupportGrant
{
    return SupportGrant::create([
        'partner_id' => $organization->partner_id,
        'organization_id' => $organization->id,
        'requested_by' => createPartnerStaff($w->partnerA, PartnerUserRole::Support)->id,
        'reason' => 'Checking the payroll settings',
        'severity' => 'normal',
        'access' => 'read',
        'duration_minutes' => 60,
        'status' => GrantStatus::Pending,
    ]);
}

it('counts rule changes and support requests waiting for this person', function () {
    orgRule($this->w->c1, 'identity.mfa_required', true, actor: User::factory()->create());
    orgRule($this->w->b1, 'identity.mfa_required', true, actor: User::factory()->create());
    pendingGrant($this->w, $this->w->c1);

    $this->asToken(orgToken($this->owner, $this->w->c1))->getJson('/api/attention')
        ->assertOk()
        ->assertExactJson(['data' => [
            ['key' => 'rules.approvals', 'label' => 'Rule changes to approve', 'count' => 2, 'path' => '/approvals', 'tone' => 'warn'],
            ['key' => 'support.requests', 'label' => 'Support access requests', 'count' => 1, 'path' => '/support-access', 'tone' => 'warn'],
        ]]);
});

it('is empty when nothing waits', function () {
    $this->asToken(orgToken($this->owner, $this->w->c1))->getJson('/api/attention')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

it('shows nothing the person may not handle', function () {
    orgRule($this->w->c1, 'identity.mfa_required', true, actor: User::factory()->create());
    pendingGrant($this->w, $this->w->c1);
    $clerk = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['audit.view'], 'Clerk'));

    $this->asToken(orgToken($clerk, $this->w->c1))->getJson('/api/attention')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

it('never counts work of another organization or partner', function () {
    orgRule($this->w->c2, 'identity.mfa_required', true, actor: User::factory()->create());
    orgRule($this->w->c4, 'identity.mfa_required', true, actor: User::factory()->create());
    pendingGrant($this->w, $this->w->c2);
    pendingGrant($this->w, $this->w->c4);

    $this->asToken(orgToken($this->owner, $this->w->c1))->getJson('/api/attention')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

it('needs an organization to work in', function () {
    $this->asToken(partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA))
        ->getJson('/api/attention')
        ->assertStatus(403);
});

it('adds the items of modules that are on here', function () {
    $manifests = array_map(
        fn (array $manifest) => $manifest['key'] === 'hrm' ? [...$manifest, 'attention' => [FixtureExpiringDocuments::class]] : $manifest,
        app(ManifestLoader::class)->load(),
    );
    app()->instance(ModuleRegistry::class, ModuleRegistry::fromManifests($manifests, app(PlanCatalog::class)->keys()));
    app()->forgetInstance(ModuleResolver::class);
    $token = orgToken($this->owner, $this->w->c1);

    $this->asToken($token)->getJson('/api/attention')->assertExactJson(['data' => []]);

    toggles()->enable($this->w->c1, 'hrm', 'Start HR');

    $this->asToken($token)->getJson('/api/attention')->assertExactJson(['data' => [
        ['key' => 'hrm.documents_expiring', 'label' => 'Documents expiring soon', 'count' => 3, 'path' => '/hrm', 'tone' => 'bad'],
    ]]);
});
