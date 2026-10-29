<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Connections\Models\Connection;
use App\Platform\Connections\Models\ConnectionGrant;
use App\Platform\Connections\Services\ConnectionGrants;
use App\Platform\Connections\Services\ConnectionLinks;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Modules\Crm\Models\Contact;

/*
 * NET-2: the client (group G1) gives its connected provider (company C3)
 * modules of its data, action by action, optionally until a day; the
 * provider's people then work inside a shared factory in a connection
 * context: only those actions, only there, checked on every request, and
 * every change in the client's log. Members, roles, rules, billing and any
 * module not given stay closed.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        setPlan($group, 'business');
        toggles()->enable($group, 'business_network', 'Test setup');
        toggles()->enable($group, 'crm', 'Test setup');
    }

    $this->providerUser = createMember($this->w->c3);
    $this->provider = orgToken($this->providerUser, $this->w->c3);
    $this->clientUser = createMember($this->w->g1);
    $this->client = orgToken($this->clientUser, $this->w->g1);
    $this->api = fn (Organization $at, string $path = '') => "/api/organizations/{$at->id}/connections{$path}";

    // C3 invites, G1 accepts sharing company C1 (with branch B1).
    $code = $this->asToken($this->provider)->postJson(($this->api)($this->w->c3, '/invitations'), [])->assertCreated()->json('data.code');
    $this->connectionId = $this->asToken($this->client)->postJson(($this->api)($this->w->g1, '/accept'), ['code' => $code, 'unit_ids' => [$this->w->c1->id]])->assertCreated()->json('data.id');
    $this->connection = fn () => Connection::query()->findOrFail($this->connectionId);

    // The client gives through its API (by token; the provider's browser session is closed first).
    $this->give = fn (array $permissions, ?string $until = null, string $module = 'crm') => $this->flushHeaders()->flushSession()->asToken($this->client)
        ->putJson(($this->api)($this->w->g1, "/{$this->connectionId}/grants/{$module}"), ['permissions' => $permissions, 'expires_on' => $until]);

    // A provider's person signs in in the browser and goes into a shared factory.
    $this->enter = function (User $user, Organization $unit) {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        spaSession($this, $user);

        return $this->postJson('/session/context', ['connection_id' => $this->connectionId, 'unit_id' => $unit->id]);
    };
    $this->contacts = fn (Organization $at) => "/api/organizations/{$at->id}/crm/contacts";
});

it('lets the client give a module action by action, and shows it to both sides', function () {
    $given = ($this->give)(['crm.view', 'crm.edit'], now()->addDays(30)->toDateString())->assertOk()->json('data');
    expect($given['modules'])->toBe(['crm'])
        ->and($given['grants'][0])->toMatchArray(['module' => 'crm', 'permissions' => ['crm.view', 'crm.edit'], 'active' => true])
        ->and(collect($given['grantable'])->pluck('module'))->toContain('crm')
        // Platform modules (connections themselves, payments, portals) are never given.
        ->and(collect($given['grantable'])->pluck('module'))->not->toContain('business_network');

    // The provider reads it (never what else the client could give) and may go in.
    $seen = $this->asToken($this->provider)->getJson(($this->api)($this->w->c3, "/{$this->connectionId}"))->assertOk()->json('data');
    expect($seen['grants'][0]['permissions'])->toBe(['crm.view', 'crm.edit'])
        ->and($seen['grantable'])->toBe([])
        ->and($seen['can'])->toMatchArray(['grants' => false, 'act' => true]);
    $this->asToken($this->provider)->getJson(($this->api)($this->w->c3))->assertOk()->assertJsonPath('data.0.modules', ['crm']);

    // Both logs keep it; changing it is the same grant, a new version.
    ($this->give)(['crm.view'])->assertOk();
    expect(ConnectionGrant::query()->sole()->version)->toBe(2)
        ->and(AuditLog::query()->where('action', 'business_network.granted')->pluck('organization_id')->unique()->values()->all())
        ->toEqualCanonicalizing([$this->w->g1->id, $this->w->c3->id]);
});

it('refuses what is not the client\'s to give', function () {
    // Only the client gives; only a module's own actions; never platform modules.
    $this->asToken($this->provider)->putJson(($this->api)($this->w->c3, "/{$this->connectionId}/grants/crm"), ['permissions' => ['crm.view']])
        ->assertForbidden()->assertJsonPath('code', 'not_your_side');
    ($this->give)(['business_network.view'], null, 'business_network')->assertUnprocessable()->assertJsonPath('code', 'module_not_grantable');
    ($this->give)(['members.manage'])->assertUnprocessable()->assertJsonPath('code', 'permissions_not_of_module');
    ($this->give)([])->assertUnprocessable()->assertJsonValidationErrors('permissions');
    ($this->give)(['crm.view'], now()->subDay()->toDateString())->assertUnprocessable()->assertJsonValidationErrors('expires_on');
    $this->asToken($this->client)->putJson(($this->api)($this->w->g1, "/{$this->connectionId}/grants/crm"), ['permissions' => ['crm.view'], 'organization_id' => $this->w->c3->id])
        ->assertUnprocessable();

    // A client person without business_network.manage cannot give.
    $viewer = staffWithRoles($this->w->g1, makeRole($this->w->g1, ['business_network.view']));
    $this->asToken(orgToken($viewer, $this->w->g1))->putJson(($this->api)($this->w->g1, "/{$this->connectionId}/grants/crm"), ['permissions' => ['crm.view']])
        ->assertForbidden();
    expect(ConnectionGrant::query()->count())->toBe(0);
});

it('lets the provider\'s person work in a shared factory with what was given, logged in the client\'s data', function () {
    ($this->give)(['crm.view', 'crm.edit'])->assertOk();

    ($this->enter)($this->providerUser, $this->w->c1)->assertOk();
    $me = $this->getJson('/api/me')->assertOk()->json('data');
    expect($me['context'])->toMatchArray(['id' => $this->w->c1->id, 'mode' => 'connection', 'account_owner' => false])
        ->and($me['context']['connection'])->toMatchArray(['provider' => 'C3', 'client' => 'G1', 'provider_id' => $this->w->c3->id])
        // What the client gave, nothing of an owner's all.
        ->and($me['permissions'])->toEqualCanonicalizing(['crm.view', 'crm.edit']);

    $made = $this->postJson(($this->contacts)($this->w->c1), ['name' => 'Karim', 'phone' => '01711-000001'])->assertCreated()->json('data');
    $this->getJson(($this->contacts)($this->w->c1))->assertOk()->assertJsonPath('data.0.id', $made['id']);
    $this->getJson('/api/menu')->assertOk();

    // In the client's own data and log, with the connection; the provider's last visit noted.
    expect(Contact::query()->withoutGlobalScope(OrganizationScope::class)->whereKey($made['id'])->value('organization_id'))->toBeIn([$this->w->c1->id])
        ->and(AuditLog::query()->where('action', 'business_network.acted')->where('organization_id', $this->w->c1->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'business_network.session_started')->pluck('organization_id')->all())
        ->toEqualCanonicalizing([$this->w->c1->id, $this->w->c3->id])
        ->and(($this->connection)()->last_used_at)->not->toBeNull();
});

it('keeps everything else closed: other factories, members, roles, billing, other modules, other tenants', function () {
    ($this->give)(['crm.view', 'crm.edit'])->assertOk();

    // A factory the client did not share.
    ($this->enter)($this->providerUser, $this->w->c2)->assertForbidden()->assertJsonPath('code', 'no_grant');

    ($this->enter)($this->providerUser, $this->w->b1)->assertOk();
    foreach (['members', 'roles', 'rules', 'billing/subscription', 'modules', 'audit', 'connections', 'exports'] as $path) {
        $response = $this->getJson("/api/organizations/{$this->w->b1->id}/{$path}");
        expect($response->status())->toBeIn([403, 404], "GET {$path} answered {$response->status()}");
    }
    $this->getJson("/api/organizations/{$this->w->b1->id}/members")->assertForbidden()->assertJsonPath('code', 'outside_grant');
    // The rest of the client's tree is not even visible.
    $this->getJson(($this->contacts)($this->w->c2))->assertNotFound();

    // A third company's person, and the provider's own staff without the act permission.
    ($this->enter)(createMember($this->w->c4), $this->w->c1)->assertForbidden()->assertJsonPath('code', 'not_allowed_to_act');
    $staff = staffWithRoles($this->w->c3, makeRole($this->w->c3, ['business_network.view']));
    ($this->enter)($staff, $this->w->c1)->assertForbidden()->assertJsonPath('code', 'not_allowed_to_act');
    // Given the permission by their own company, they may.
    $actor = staffWithRoles($this->w->c3, makeRole($this->w->c3, ['business_network.view', 'business_network.act']));
    ($this->enter)($actor, $this->w->c1)->assertOk();

    // Tokens never carry a connection context.
    $this->flushHeaders();
    $this->asToken($this->provider)->postJson('/api/auth/context', ['connection_id' => $this->connectionId, 'unit_id' => $this->w->c1->id])
        ->assertUnprocessable();
});

it('allows only the given actions, and stops at once when taken back, past its day, paused or the module off', function () {
    ($this->give)(['crm.view'], now()->addDays(2)->toDateString())->assertOk();
    $client = $this->clientUser;

    ($this->enter)($this->providerUser, $this->w->c1)->assertOk();
    $this->getJson(($this->contacts)($this->w->c1))->assertOk();
    // Seeing only: no changes.
    $this->postJson(($this->contacts)($this->w->c1), ['name' => 'Karim', 'phone' => '01711-000001'])->assertForbidden();
    $this->getJson('/api/me')->assertJsonPath('data.context.connection.until', fn ($until) => $until !== null);

    // Taken back: the very next request.
    app(ConnectionGrants::class)->revoke(($this->connection)(), $this->w->g1, 'crm', $client);
    app(CurrentContext::class)->clear();
    $this->getJson(($this->contacts)($this->w->c1))->assertForbidden()->assertJsonPath('code', 'no_grant');
    expect(AuditLog::query()->where('action', 'business_network.revoked')->count())->toBe(2);

    // Given again, then past its last day.
    ($this->give)(['crm.view'], now()->toDateString())->assertOk();
    ($this->enter)($this->providerUser, $this->w->c1)->assertOk();
    $this->travel(2)->days();
    app(CurrentContext::class)->clear();
    $this->getJson(($this->contacts)($this->w->c1))->assertForbidden();
    $this->travelBack();

    // Paused by either side: nothing until resumed.
    ($this->give)(['crm.view'])->assertOk();
    ($this->enter)($this->providerUser, $this->w->c1)->assertOk();
    app(ConnectionLinks::class)->step(($this->connection)(), $this->w->g1, 'pause', ($this->connection)()->version, null, $client);
    app(CurrentContext::class)->clear();
    $this->getJson(($this->contacts)($this->w->c1))->assertForbidden();
    app(ConnectionLinks::class)->step(($this->connection)(), $this->w->g1, 'resume', ($this->connection)()->version, null, $client);

    // The module turned off at the factory: nothing to work in (the module's own 403 first).
    toggles()->disable($this->w->c1, 'crm', 'Test');
    app(CurrentContext::class)->clear();
    $this->getJson(($this->contacts)($this->w->c1))->assertForbidden();
    ($this->enter)($this->providerUser, $this->w->c1)->assertForbidden()->assertJsonPath('code', 'no_grant');
});

it('works in a client kept in its own database', function () {
    dedicatedTenantDatabase();
    placeClient($this->w->g1);
    ($this->give)(['crm.view', 'crm.edit'])->assertOk();

    ($this->enter)($this->providerUser, $this->w->c1)->assertOk();
    $made = $this->postJson(($this->contacts)($this->w->c1), ['name' => 'Karim', 'phone' => '01711-000001'])->assertCreated()->json('data');

    expect(Contact::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->whereKey($made['id'])->exists())->toBeTrue();
});

it('needs business_network.act to show the way in', function () {
    ($this->give)(['crm.view'])->assertOk();
    $staff = staffWithRoles($this->w->c3, makeRole($this->w->c3, ['business_network.view']));
    $this->asToken(orgToken($staff, $this->w->c3))->getJson(($this->api)($this->w->c3, "/{$this->connectionId}"))
        ->assertOk()->assertJsonPath('data.can.act', false);
});
