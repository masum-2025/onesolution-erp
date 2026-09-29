<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Organization;

/*
 * A client's shape: a single company at the top (no group), a group with
 * its first company, or another company in a group the partner already
 * serves. Branches are optional. Both the partner (at creation) and the
 * client's owner (branches, companies in their group) can build it.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->ownerToken = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA);
    $this->head = User::factory()->create(['email' => 'head@school.test']);
});

function addClient(object $test, array $overrides = [])
{
    return $test->asToken($test->ownerToken)->postJson('http://localhost/api/partner/clients', [
        'name' => ['en' => 'Sunrise School', 'bn' => 'সানরাইজ স্কুল'],
        'sector_key' => 'school',
        'plan' => 'business',
        'owner_email' => 'head@school.test',
        'country_code' => 'BD',
        ...$overrides,
    ]);
}

it('creates a single company at the top by default, with no group', function () {
    $response = addClient($this)->assertCreated()->assertJsonPath('branches', []);

    $company = Organization::findOrFail($response->json('data.id'));
    expect($response->json('company.id'))->toBe($company->id)
        ->and($company->type)->toBe(OrganizationType::Company)
        ->and($company->isRoot())->toBeTrue()
        ->and($company->plan_key)->toBe('business')
        ->and($company->country_code)->toBe('BD')
        ->and(resolvedModule('payroll', $company)->enabled)->toBeTrue()
        ->and($company->memberships()->where('user_id', $this->head->id)->value('membership_type')->value)->toBe('owner');

    expect(AuditLog::query()->where('action', 'partner.client_created')->sole()->new_values['structure'])->toBe('company');
});

it('creates the first branches when asked, and none otherwise', function () {
    $response = addClient($this, ['branches' => [['en' => 'North Campus', 'bn' => 'উত্তর ক্যাম্পাস'], ['en' => 'South Campus']]])->assertCreated();

    $company = Organization::findOrFail($response->json('company.id'));
    $branches = Organization::query()->where('parent_id', $company->id)->get();

    expect($branches)->toHaveCount(2)
        ->and($branches->every(fn (Organization $branch) => $branch->type === OrganizationType::Branch))->toBeTrue()
        ->and($branches->firstWhere(fn ($b) => $b->texts('name')['en'] === 'North Campus')->texts('name')['bn'])->toBe('উত্তর ক্যাম্পাস');
});

it('keeps the plan\'s branch limit when creating first branches', function () {
    partnerRule($this->w->partnerA, 'plans.max_branches', 2);

    addClient($this, ['plan' => 'starter', 'branches' => [['en' => 'B1'], ['en' => 'B2'], ['en' => 'B3']]])
        ->assertUnprocessable();

    expect(Organization::query()->whereJsonContains('name->en', 'Sunrise School')->exists())->toBeFalse();
});

it('creates a group with its first company when asked', function () {
    $response = addClient($this, ['structure' => 'group', 'group_name' => ['en' => 'Sunrise Education']])->assertCreated();

    $group = Organization::findOrFail($response->json('data.id'));
    $company = Organization::findOrFail($response->json('company.id'));

    expect($group->type)->toBe(OrganizationType::Group)
        ->and($group->texts('name')['en'])->toBe('Sunrise Education')
        ->and($company->parent_id)->toBe($group->id)
        ->and($group->plan_key)->toBe('business');
});

it('adds a company to a group it already serves, billed with the group, and tells its owners', function () {
    $groupOwner = createMember($this->w->g1, MembershipType::Owner);
    $clients = Organization::query()->whereNull('parent_id')->count();

    $response = addClient($this, [
        'structure' => 'existing_group',
        'group_id' => $this->w->g1->id,
        'name' => ['en' => 'Sunrise College'],
        'plan' => null,
        'owner_email' => null,
    ])->assertCreated()->assertJsonPath('data.id', $this->w->g1->id);

    $company = Organization::findOrFail($response->json('company.id'));
    expect($company->parent_id)->toBe($this->w->g1->id)
        ->and(Organization::query()->whereNull('parent_id')->count())->toBe($clients)
        ->and(AuditLog::query()->where('action', 'partner.company_added')->exists())->toBeTrue()
        ->and(NotificationDelivery::query()->where('notification_key', 'partners.company_added')->where('user_id', $groupOwner->id)->exists())->toBeTrue();
});

it('does not use a client slot for a company added to a group', function () {
    // Partner A serves two clients already (G1, G2).
    partnerRule($this->w->partnerA, 'partners.max_clients', 2);

    addClient($this)->assertUnprocessable()->assertJsonPath('code', 'client_limit_reached');
    addClient($this, ['structure' => 'existing_group', 'group_id' => $this->w->g1->id, 'plan' => null])->assertCreated();
});

it('never adds a company to another partner\'s group, a single company or a suspended group', function () {
    addClient($this, ['structure' => 'existing_group', 'group_id' => $this->w->g3->id, 'plan' => null])
        ->assertNotFound()->assertJsonPath('code', 'client_not_found');

    $single = Organization::findOrFail(addClient($this, ['name' => ['en' => 'Alone']])->json('data.id'));
    addClient($this, ['structure' => 'existing_group', 'group_id' => $single->id, 'plan' => null])->assertNotFound();

    $this->w->g2->forceFill(['status' => OrganizationStatus::Suspended])->save();
    addClient($this, ['structure' => 'existing_group', 'group_id' => $this->w->g2->id, 'plan' => null])
        ->assertUnprocessable()->assertJsonPath('code', 'group_not_active');
});

it('validates the shape of the request', function (array $overrides, string $field) {
    addClient($this, $overrides)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unknown structure' => [['structure' => 'holding'], 'structure'],
    'group id without existing group' => [['group_id' => '01J0000000000000000000000A'], 'group_id'],
    'existing group without id' => [['structure' => 'existing_group', 'plan' => null], 'group_id'],
    'plan in an existing group' => [['structure' => 'existing_group', 'group_id' => '01J0000000000000000000000A'], 'plan'],
    'branch without english name' => [['branches' => [['bn' => 'শাখা']]], 'branches.0.en'],
    'too many branches' => [['branches' => array_fill(0, 21, ['en' => 'Branch'])], 'branches'],
]);

it('lets a partner require every company to be in a group (rule)', function () {
    partnerRule($this->w->partnerA, 'tenancy.allowed_parents', [
        'group' => ['root'], 'company' => ['group'], 'branch' => ['company'], 'department' => ['branch', 'company'],
    ]);

    addClient($this)->assertUnprocessable()->assertJsonPath('code', 'invalid_parent');
    addClient($this, ['structure' => 'group'])->assertCreated();
});

it('lets the owner of a single company open branches, and a group owner open companies', function () {
    $single = Organization::findOrFail(addClient($this)->json('data.id'));

    $this->asToken(orgToken($this->head, $single))->postJson('/api/organizations', [
        'parent_id' => $single->id, 'type' => 'branch', 'name' => ['en' => 'Main Campus'],
    ])->assertCreated();

    $groupOwner = createMember($this->w->g1, MembershipType::Owner);
    $this->asToken(orgToken($groupOwner, $this->w->g1))->postJson('/api/organizations', [
        'parent_id' => $this->w->g1->id, 'type' => 'company', 'name' => ['en' => 'New College'], 'sector_key' => 'school',
    ])->assertCreated();
});
