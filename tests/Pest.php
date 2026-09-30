<?php

use App\Models\User;
use App\Platform\Access\Models\MembershipRole;
use App\Platform\Access\Models\Role;
use App\Platform\Identity\Services\PersonalWorkspaces;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Modules\ResolvedModule;
use App\Platform\Modules\Services\ModuleToggleService;
use App\Platform\Partners\Contracts\DnsTxtLookup;
use App\Platform\Partners\Enums\DomainStatus;
use App\Platform\Partners\HostContext;
use App\Platform\Partners\Models\PartnerDomain;
use App\Platform\Payments\DecimalAmount;
use App\Platform\Payments\GatewayRegistry;
use App\Platform\Payments\Models\Payment;
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
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Databases\TenantPlacements;
use App\Platform\Tenancy\Databases\TenantTables;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use App\Platform\Tenancy\Models\Partner;
use App\Platform\Tenancy\Models\PartnerUser;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\TenantNote;
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

/**
 * A person who never picked a language in their profile: they read the
 * organization's (or its country's) language (Phase 6). Factory users read English.
 */
function withoutOwnLanguage(User $user): User
{
    $user->forceFill(['locale' => null])->save();

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
    app(HostContext::class)->forPlatform();
    $token = app(IssueContextToken::class)->forOrganization($user, $organization->getKey())->plainTextToken;
    app(CurrentContext::class)->clear();

    return $token;
}

function partnerToken(User $user, Partner $partner): string
{
    app(HostContext::class)->forPlatform();
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
| Tenant databases (Phase 10)
|--------------------------------------------------------------------------
*/

/**
 * Create a database next to the test database if it is missing (outside any
 * transaction; the name already carries the parallel-process suffix).
 */
function ensureTestDatabase(string $name): void
{
    $config = config('database.connections.'.config('database.default'));

    if ($config['driver'] === 'pgsql') {
        $pdo = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname=postgres", $config['username'], $config['password']);
        $exists = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $exists->execute([$name]);
        if ($exists->fetchColumn() === false) {
            $pdo->exec('CREATE DATABASE "'.$name.'"');
        }
    } else {
        $pdo = new PDO("mysql:host={$config['host']};port={$config['port']}", $config['username'], $config['password']);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$name.'`');
    }
}

/**
 * A dedicated tenant database for this test: "<test database>_<name>", with
 * the tenant tables (migrated fresh once per process). Everything the test
 * writes there is rolled back afterwards. Returns the connection name.
 */
function dedicatedTenantDatabase(string $name = 'dedicated'): string
{
    static $migrated = [];

    $database = config('database.connections.'.config('database.default').'.database').'_'.$name;
    ensureTestDatabase($database);
    $connection = TenantDatabases::register($name, ['database' => $database]);

    if (! isset($migrated[$database])) {
        Artisan::call('migrate:fresh', [
            '--database' => $connection,
            '--path' => app(TenantTables::class)->migrationPaths(),
            '--realpath' => true,
            '--force' => true,
        ]);
        $migrated[$database] = true;
    }

    DB::connection($connection)->beginTransaction();
    test()->beforeApplicationDestroyed(function () use ($connection) {
        $db = DB::connection($connection);
        while ($db->transactionLevel() > 0) {
            $db->rollBack();
        }
        $db->disconnect();
    });

    return $connection;
}

/**
 * A stored fixture row, read by system code from its client's database.
 */
function storedNote(TenantNote $note): ?TenantNote
{
    return TenantNote::inTenantOf($note->organization_id)->withoutGlobalScope(OrganizationScope::class)->find($note->id);
}

/**
 * Put a client (with no business data yet) into a tenant database, as
 * tenants:place does.
 */
function placeClient(Organization $root, ?string $database = 'dedicated'): void
{
    app(TenantPlacements::class)->place($root->fresh(), $database, 'Test setup');
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

/*
|--------------------------------------------------------------------------
| Self-serve billing helpers (Phase 5C-2)
|--------------------------------------------------------------------------
*/

const SSL_STORE_PASSWORD = 'test-store-password';

/**
 * The house partner selling personal plans in Bangladesh through the
 * SSLCommerz sandbox, and one person (verified email) with their workspace.
 */
function selfServeWorld(?Partner $partner = null, string $country = 'BD'): object
{
    config([
        'payments.sslcommerz.store_id' => 'teststore',
        'payments.sslcommerz.store_password' => SSL_STORE_PASSWORD,
    ]);
    app()->forgetInstance(GatewayRegistry::class);

    $partner ??= Partner::query()->where('is_house', true)->first() ?? Partner::factory()->house()->create(['name' => 'One Solutions']);
    platformRule('billing.payment_gateways', ['sslcommerz'], 'BD');

    $user = User::factory()->create();
    $workspace = app(PersonalWorkspaces::class)->create($user, $partner, $country, 'en');

    return (object) ['partner' => $partner, 'user' => $user, 'workspace' => $workspace];
}

/**
 * SSLCommerz sandbox answers: the payment page opens, and a val_id
 * "VAL-{payment id}" validates as that payment for its own amount unless
 * $validation overrides fields. Lookups find nothing unless $lookup is given.
 *
 * @param  array<string, mixed>  $validation
 * @param  list<array<string, mixed>>|null  $lookup
 */
function fakeSslCommerz(array $validation = [], ?array $lookup = null, bool $initFails = false): void
{
    // Replaces any earlier fake of this test (the first matching fake would win otherwise).
    Http::swap(new Factory(app('events')));

    Http::fake(function (Request $request) use ($validation, $lookup, $initFails) {
        $url = $request->url();

        if (str_contains($url, '/gwprocess/v4/api.php')) {
            return $initFails
                ? Http::response(['status' => 'FAILED', 'failedreason' => 'Store is not active'])
                : Http::response([
                    'status' => 'SUCCESS',
                    'GatewayPageURL' => 'https://sandbox.sslcommerz.com/EasyCheckOut/test'.$request['tran_id'],
                    'sessionkey' => 'SK'.$request['tran_id'],
                ]);
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (str_contains($url, 'validationserverAPI.php')) {
            $tranId = substr((string) ($query['val_id'] ?? ''), 4);
            $payment = Payment::query()->find($tranId);
            $amount = $payment === null ? '0.00' : DecimalAmount::fromMinor($payment->amount_minor, $payment->currency_code);

            return Http::response([
                'status' => 'VALID',
                'tran_id' => $tranId,
                'val_id' => $query['val_id'],
                'amount' => $amount,
                'currency' => 'BDT',
                'currency_type' => 'BDT',
                'currency_amount' => $amount,
                'card_type' => 'BKASH-BKash',
                'card_no' => '01711XXXXXX',
                'risk_level' => '0',
                ...$validation,
            ]);
        }

        if (str_contains($url, 'merchantTransIDvalidationAPI.php')) {
            return Http::response(['APIConnect' => 'DONE', 'no_of_trans_found' => count($lookup ?? []), 'element' => $lookup ?? []]);
        }

        return Http::response('Unexpected', 500);
    });
}

/**
 * What SSLCommerz posts to our notice (IPN) or return URLs, signed with the
 * store password the way SSLCommerz does (or with a wrong one).
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function sslNotice(Payment $payment, string $status = 'VALID', array $extra = [], string $password = SSL_STORE_PASSWORD): array
{
    $fields = [
        'status' => $status,
        'tran_id' => $payment->getKey(),
        'val_id' => $status === 'VALID' ? 'VAL-'.$payment->getKey() : '',
        'amount' => DecimalAmount::fromMinor($payment->amount_minor, $payment->currency_code),
        'currency' => $payment->currency_code,
        ...$extra,
    ];

    $keys = array_keys($fields);
    $signed = [...$fields, 'store_passwd' => md5($password)];
    ksort($signed);

    return [
        ...$fields,
        'card_no' => '432149XXXXXX0667',
        'verify_key' => implode(',', $keys),
        'verify_sign' => md5(implode('&', array_map(fn ($key, $value) => "{$key}={$value}", array_keys($signed), $signed))),
    ];
}

/**
 * Start a checkout through the API as the workspace owner.
 */
function startCheckout(TestCase $test, object $world, string $plan = 'personal_plus', string $period = 'monthly', ?string $opId = null): TestResponse
{
    return $test->asToken(orgToken($world->user, $world->workspace))
        ->postJson("/api/organizations/{$world->workspace->id}/billing/checkout", [
            'plan_key' => $plan,
            'period' => $period,
            'op_id' => $opId ?? (string) Str::ulid(),
        ]);
}
