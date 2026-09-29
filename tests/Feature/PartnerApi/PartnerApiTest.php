<?php

use App\Platform\Audit\AuditLog;
use App\Platform\PartnerApi\Models\PartnerApiKey;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\PartnerUser;

/*
 * Phase 5B-5: a partner's own systems provision clients, members and plans
 * with an API key; scopes, revocation, isolation, idempotency and rate
 * limits hold; every change is audited with the key.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->owner = createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner);
    $this->consoleToken = partnerToken($this->owner, $this->w->partnerA);
});

function apiKey(object $test, array $scopes = PartnerApiKey::SCOPES, ?string $token = null): string
{
    return $test->asToken($token ?? $test->consoleToken)->postJson('http://localhost/api/partner/api-keys', ['name' => 'Website', 'scopes' => $scopes])
        ->assertCreated()->json('data.key');
}

function api(object $test, string $key): object
{
    app('auth')->forgetGuards();
    app(CurrentContext::class)->clear();

    return $test->withToken($key);
}

function newApiClient(object $test, string $key, array $headers = [], array $overrides = [])
{
    return api($test, $key)->withHeaders($headers)->postJson('http://localhost/api/partner/v1/clients', [
        'name' => ['en' => 'Sunrise School'],
        'sector_key' => 'school',
        'plan' => 'business',
        'owner_email' => 'head@sunrise.test',
        'owner_name' => 'Head Teacher',
        'country_code' => 'BD',
        ...$overrides,
    ]);
}

it('shows a key once and keeps only its hash', function () {
    $key = apiKey($this);
    expect($key)->toMatch('/^osk_[a-z0-9]{8}_[A-Za-z0-9]{40}$/');

    $record = PartnerApiKey::sole();
    expect($record->secret_hash)->toBe(hash('sha256', $key))
        ->and($record->prefix)->toBe(substr($key, 0, 12));

    $list = $this->asToken($this->consoleToken)->getJson('http://localhost/api/partner/api-keys')->assertOk()
        ->assertJsonPath('data.0.prefix', $record->prefix)
        ->assertJsonPath('data.0.status', 'active');
    expect($list->getContent())->not->toContain(substr($key, 13));
});

it('lets only owners manage keys', function () {
    $billing = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Billing), $this->w->partnerA);

    $this->asToken($billing)->postJson('http://localhost/api/partner/api-keys', ['name' => 'Mine', 'scopes' => ['clients:read']])->assertForbidden();
    $this->asToken($this->consoleToken)->postJson('http://localhost/api/partner/api-keys', ['name' => 'Bad', 'scopes' => ['everything']])
        ->assertUnprocessable()->assertJsonValidationErrors('scopes.0');
});

it('provisions a client with its owner invited, audited with the key', function () {
    $key = apiKey($this);

    $id = newApiClient($this, $key)->assertCreated()->assertJsonPath('owner_invited', true)->json('data.id');

    expect(Organization::find($id)->partner_id)->toBe($this->w->partnerA->id);
    $audit = AuditLog::where('action', 'partner.client_created')->sole();
    expect($audit->api_key_id)->toBe(PartnerApiKey::sole()->id)
        ->and($audit->actor_user_id)->toBe($this->owner->id);

    api($this, $key)->getJson("http://localhost/api/partner/v1/clients/{$id}")->assertOk()
        ->assertJsonPath('data.subscription.plan', 'business');
    api($this, $key)->getJson('http://localhost/api/partner/v1/clients')->assertOk()->assertJsonPath('meta.total', 3);
});

it('provisions a single company with branches, or a company in a served group', function () {
    $key = apiKey($this);

    $single = newApiClient($this, $key, overrides: ['branches' => [['en' => 'Main Campus']]])->assertCreated();
    expect(Organization::find($single->json('data.id'))->type->value)->toBe('company')
        ->and($single->json('branches'))->toHaveCount(1);

    newApiClient($this, $key, overrides: ['structure' => 'existing_group', 'group_id' => $this->w->g1->id, 'plan' => null, 'owner_email' => null, 'owner_name' => null])
        ->assertCreated()->assertJsonPath('data.id', $this->w->g1->id);

    // Another partner's group is out of reach.
    newApiClient($this, $key, overrides: ['structure' => 'existing_group', 'group_id' => $this->w->g3->id, 'plan' => null, 'owner_email' => null, 'owner_name' => null])
        ->assertNotFound();
});

it('adds members and changes plans through the API', function () {
    $key = apiKey($this);

    api($this, $key)->postJson("http://localhost/api/partner/v1/clients/{$this->w->g1->id}/members", [
        'email' => 'teacher@sunrise.test', 'name' => 'A Teacher', 'organization_id' => $this->w->c1->id, 'membership_type' => 'staff', 'access_scope' => 'own',
    ])->assertCreated()->assertJsonPath('data.invited', true)->assertJsonPath('data.organization_id', $this->w->c1->id);

    // A unit of another client is not reachable through this client.
    api($this, $key)->postJson("http://localhost/api/partner/v1/clients/{$this->w->g1->id}/members", [
        'email' => 'x@sunrise.test', 'name' => 'X Person', 'organization_id' => $this->w->c3->id, 'membership_type' => 'staff',
    ])->assertNotFound();

    api($this, $key)->putJson("http://localhost/api/partner/v1/clients/{$this->w->g1->id}/plan", ['plan' => 'enterprise'])->assertOk()
        ->assertJsonPath('data.plan.key', 'enterprise');
    expect(AuditLog::where('action', 'organization.plan_changed')->sole()->reason)->toBe('Changed through the API (Website)');
});

it('does only what the key\'s scopes allow', function () {
    $readOnly = apiKey($this, ['clients:read']);

    api($this, $readOnly)->getJson('http://localhost/api/partner/v1/clients')->assertOk();
    newApiClient($this, $readOnly)->assertForbidden()->assertJsonPath('code', 'missing_scope')->assertJsonPath('scope', 'clients:write');
    api($this, $readOnly)->getJson('http://localhost/api/partner/v1/plans')->assertForbidden();
});

it('never reaches another partner\'s clients', function () {
    $key = apiKey($this);

    api($this, $key)->getJson("http://localhost/api/partner/v1/clients/{$this->w->g3->id}")->assertNotFound();
    api($this, $key)->putJson("http://localhost/api/partner/v1/clients/{$this->w->g3->id}/plan", ['plan' => 'starter'])->assertNotFound();
});

it('stops working when revoked, expired, or when its creator is no longer an owner', function () {
    $key = apiKey($this);
    api($this, $key)->getJson('http://localhost/api/partner/v1/clients')->assertOk();

    api($this, 'osk_wrongkey_'.str_repeat('a', 40))->getJson('http://localhost/api/partner/v1/clients')->assertUnauthorized()->assertJsonPath('code', 'invalid_key');
    api($this, substr($key, 0, -1).'X')->getJson('http://localhost/api/partner/v1/clients')->assertUnauthorized();

    PartnerUser::where('user_id', $this->owner->id)->update(['role' => PartnerUserRole::Sales]);
    api($this, $key)->getJson('http://localhost/api/partner/v1/clients')->assertUnauthorized();
    PartnerUser::where('user_id', $this->owner->id)->update(['role' => PartnerUserRole::Owner]);

    $this->travel(366)->days();
    api($this, $key)->getJson('http://localhost/api/partner/v1/clients')->assertUnauthorized();
    $this->travelBack();

    $this->asToken(partnerToken($this->owner, $this->w->partnerA))->deleteJson('http://localhost/api/partner/api-keys/'.PartnerApiKey::sole()->id)->assertOk();
    api($this, $key)->getJson('http://localhost/api/partner/v1/clients')->assertUnauthorized();

    // Console sessions and tokens are not API keys.
    api($this, $this->consoleToken)->getJson('http://localhost/api/partner/v1/clients')->assertUnauthorized();
});

it('does a write once when it is retried with the same Idempotency-Key', function () {
    $key = apiKey($this);
    $headers = ['Idempotency-Key' => 'signup-4711'];

    $first = newApiClient($this, $key, $headers)->assertCreated();
    newApiClient($this, $key, $headers)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));

    expect(Organization::whereNull('parent_id')->where('partner_id', $this->w->partnerA->id)->count())->toBe(3);

    newApiClient($this, $key, $headers, ['name' => ['en' => 'Another School']])->assertUnprocessable()->assertJsonPath('code', 'idempotency_mismatch');
});

it('limits requests per key', function () {
    partnerRule($this->w->partnerA, 'partners.api_rate_per_minute', 2);
    $key = apiKey($this);

    api($this, $key)->getJson('http://localhost/api/partner/v1/clients')->assertOk();
    api($this, $key)->getJson('http://localhost/api/partner/v1/clients')->assertOk();
    api($this, $key)->getJson('http://localhost/api/partner/v1/clients')->assertStatus(429);
});
