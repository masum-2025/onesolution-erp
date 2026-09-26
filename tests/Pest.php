<?php

use App\Models\User;
use App\Platform\Access\Models\MembershipRole;
use App\Platform\Access\Models\Role;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Partners\Contracts\DnsTxtLookup;
use App\Platform\Partners\Enums\DomainStatus;
use App\Platform\Partners\Models\PartnerDomain;
use App\Platform\Modules\ResolvedModule;
use App\Platform\Modules\Services\ModuleToggleService;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Rules\RuleTargets;
use App\Platform\Rules\Services\RuleService;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Actions\CreateOrganization;
use App\Platform\Tenancy\Actions\IssueContextToken;
use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use App\Platform\Tenancy\Models\Partner;
use App\Platform\Tenancy\Models\PartnerUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Tenancy helpers
|--------------------------------------------------------------------------
*/

function createGroup(Partner $partner, string $name = 'Group', array $attributes = []): Organization
{
    return app(CreateOrganization::class)->handle(
        OrganizationType::Group,
        ['name' => ['en' => $name], ...$attributes],
        partner: $partner,
    );
}

function createChild(Organization $parent, OrganizationType $type, string $name, array $attributes = []): Organization
{
    $defaults = $type === OrganizationType::Company ? ['sector_key' => 'school'] : [];

    return app(CreateOrganization::class)->handle(
        $type,
        ['name' => ['en' => $name], ...$defaults, ...$attributes],
        parent: $parent,
    );
}

function createMember(
    Organization $organization,
    MembershipType $type = MembershipType::Owner,
    AccessScope $scope = AccessScope::Own,
): User {
    $user = User::factory()->create();
    app(AddMember::class)->handle($organization, $user, $type, $scope);

    return $user;
}

function createPartnerStaff(Partner $partner, PartnerUserRole $role = PartnerUserRole::Support): User
{
    $user = User::factory()->create();

    PartnerUser::create([
        'partner_id' => $partner->getKey(),
        'user_id' => $user->getKey(),
        'role' => $role,
        'status' => MembershipStatus::Active,
    ]);

    return $user;
}

/**
 * Issue an organization-context token the way the API does, then reset the
 * in-memory context so the next request starts clean.
 */
function orgToken(User $user, Organization $organization): string
{
    // Tokens are issued at the platform address, whatever the last test request used.
    app(App\Platform\Partners\HostContext::class)->forPlatform();
    $token = app(IssueContextToken::class)->forOrganization($user, $organization->getKey())->plainTextToken;
    app(CurrentContext::class)->clear();

    return $token;
}

function partnerToken(User $user, Partner $partner): string
{
    app(App\Platform\Partners\HostContext::class)->forPlatform();
    $token = app(IssueContextToken::class)->forPartner($user, $partner->getKey())->plainTextToken;
    app(CurrentContext::class)->clear();

    return $token;
}

/**
 * Sign in the way the browser app does (cookie session, first-party Origin)
 * and optionally enter an organization or partner console. Factory users
 * have the password "password".
 */
function spaSession(TestCase $test, User $user, ?Organization $organization = null, ?Partner $partner = null): TestCase
{
    $test->withHeader('Origin', config('app.url'));
    $test->postJson('/session/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

    if ($organization !== null) {
        $test->postJson('/session/context', ['organization_id' => $organization->getKey()])->assertOk();
    }

    if ($partner !== null) {
        $test->postJson('/session/context', ['partner_id' => $partner->getKey()])->assertOk();
    }

    app(CurrentContext::class)->clear();

    return $test;
}

/**
 * Put the current process into a user's organization context (model-level tests).
 */
function actInOrganization(User $user, Organization $organization): CurrentContext
{
    return app(ContextResolver::class)->enterOrganization($user, $organization->getKey());
}

/**
 * Set an organization's interim plan (commercial data, normally set by the partner).
 */
function setPlan(Organization $organization, string $plan): void
{
    $organization->forceFill(['plan_key' => $plan])->save();
}

function toggles(): ModuleToggleService
{
    return app(ModuleToggleService::class);
}

function ruleService(): RuleService
{
    return app(RuleService::class);
}

/**
 * Store a platform-level value the way platform tooling does (no approval).
 */
function platformRule(string $key, mixed $value, ?string $country = null, RuleMode $mode = RuleMode::Set): RuleValue
{
    return ruleService()->set(app(RuleTargets::class)->platform(), $key, $mode, $value, 'Test setup', countryCode: $country, trusted: true);
}

function orgRule(Organization $organization, string $key, mixed $value, RuleMode $mode = RuleMode::Set, ?User $actor = null): RuleValue
{
    return ruleService()->set(app(RuleTargets::class)->organization($organization->fresh()), $key, $mode, $value, 'Test setup', $actor);
}

function ruleFor(string $key, Organization $organization): mixed
{
    return app(RuleResolver::class)->get($key, app(RuleContextFactory::class)->forOrganization($organization->fresh()));
}

function resolvedModule(string $key, Organization $organization): ResolvedModule
{
    return app(ModuleResolver::class)->resolve($key, $organization->fresh());
}

/**
 * Standard world used across isolation tests:
 *
 *   Partner A ─ Group G1 ─┬─ Company C1 ─ Branch B1 ─ Department D1
 *             │           └─ Company C2 ─ Branch B2
 *             └ Group G2 ─── Company C3
 *   Partner B ─ Group G3 ─── Company C4
 */
function tenancyWorld(): object
{
    $partnerA = Partner::factory()->create(['name' => 'Partner A']);
    $partnerB = Partner::factory()->create(['name' => 'Partner B']);

    $g1 = createGroup($partnerA, 'G1', ['country_code' => 'BD', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
    $c1 = createChild($g1, OrganizationType::Company, 'C1');
    $b1 = createChild($c1, OrganizationType::Branch, 'B1');
    $d1 = createChild($b1, OrganizationType::Department, 'D1');
    $c2 = createChild($g1, OrganizationType::Company, 'C2');
    $b2 = createChild($c2, OrganizationType::Branch, 'B2');

    $g2 = createGroup($partnerA, 'G2');
    $c3 = createChild($g2, OrganizationType::Company, 'C3');

    $g3 = createGroup($partnerB, 'G3');
    $c4 = createChild($g3, OrganizationType::Company, 'C4');

    return (object) compact('partnerA', 'partnerB', 'g1', 'c1', 'b1', 'd1', 'c2', 'b2', 'g2', 'c3', 'g3', 'c4');
}

/*
|--------------------------------------------------------------------------
| Access helpers (Phase 4)
|--------------------------------------------------------------------------
*/

/**
 * A role fixture written straight to the tables (setup only; the API and
 * RoleService are tested separately).
 *
 * @param  list<string>  $permissions
 */
function makeRole(Organization $organization, array $permissions, string $name = 'Role'): Role
{
    $role = Role::create([
        'organization_id' => $organization->getKey(),
        'key' => Str::slug($name, '_').'_'.Str::lower(Str::random(6)),
        'name' => ['en' => $name],
        'version' => 1,
    ]);

    DB::table('role_permissions')->insert(array_map(
        fn (string $key) => ['role_id' => $role->getKey(), 'permission_key' => $key],
        $permissions,
    ));

    return $role;
}

function giveRoles(User $user, Organization $organization, Role ...$roles): OrganizationMembership
{
    $membership = OrganizationMembership::query()
        ->where('user_id', $user->getKey())
        ->where('organization_id', $organization->getKey())
        ->firstOrFail();

    foreach ($roles as $role) {
        MembershipRole::create([
            'organization_id' => $organization->getKey(),
            'membership_id' => $membership->getKey(),
            'role_id' => $role->getKey(),
        ]);
    }

    return $membership;
}

/**
 * A staff member holding the given roles.
 */
function staffWithRoles(Organization $organization, Role ...$roles): User
{
    $user = createMember($organization, MembershipType::Staff);
    giveRoles($user, $organization, ...$roles);

    return $user;
}

/*
|--------------------------------------------------------------------------
| Partner layer helpers (Phase 5B)
|--------------------------------------------------------------------------
*/

/**
 * A verified custom domain (skips DNS; the DNS flow is tested separately).
 */
function activeDomain(Partner $partner, string $host, ?Organization $client = null): PartnerDomain
{
    return PartnerDomain::create([
        'partner_id' => $partner->getKey(),
        'organization_id' => $client?->getKey(),
        'host' => $host,
        'status' => DomainStatus::Active,
        'verification_token' => Str::random(40),
        'verified_at' => now(),
    ]);
}

/**
 * DNS answers for this test only: name => list of TXT values.
 *
 * @param  array<string, list<string>>  $records
 */
function fakeDns(array $records): void
{
    app()->instance(DnsTxtLookup::class, new class($records) implements DnsTxtLookup
    {
        public function __construct(private array $records) {}

        public function txt(string $name): array
        {
            return $this->records[$name] ?? [];
        }
    });
}

/**
 * Store a value for one partner the way platform operators do (rules:set --partner).
 */
function partnerRule(Partner $partner, string $key, mixed $value, RuleMode $mode = RuleMode::Set): RuleValue
{
    return ruleService()->set(app(RuleTargets::class)->partner($partner), $key, $mode, $value, 'Test setup', trusted: true);
}
