<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Connections\ConnectionKinds;
use App\Platform\Connections\Exceptions\ConnectionException;
use App\Platform\Connections\Jobs\DeliverConnectionMessage;
use App\Platform\Connections\Models\Connection;
use App\Platform\Connections\Models\ConnectionMessage;
use App\Platform\Connections\Services\Connections;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\FixtureConnectionNote;
use Tests\Fixtures\FixtureConnectionReply;
use Tests\Fixtures\TenantNote;

/*
 * NET-1: business connections. A service provider (company C3, group G2)
 * invites its client (group G1: companies C1 > B1 and C2 > B2) by a code;
 * the client accepts sharing chosen companies and branches. Messages cross
 * only while active, only to shared units, and land in the receiver's own
 * data (its own database too); payloads are wiped once delivered.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        setPlan($group, 'business');
        toggles()->enable($group, 'business_network', 'Test setup');
        toggles()->enable($group, 'crm', 'Test setup');
    }
    app(ConnectionKinds::class)->register('crm', FixtureConnectionNote::class);
    app(ConnectionKinds::class)->register('crm', FixtureConnectionReply::class);
    FixtureConnectionNote::$received = [];

    $this->providerUser = createMember($this->w->c3);
    $this->provider = orgToken($this->providerUser, $this->w->c3);
    $this->clientUser = createMember($this->w->g1);
    $this->client = orgToken($this->clientUser, $this->w->g1);
    $this->api = fn (Organization $at, string $path = '') => "/api/organizations/{$at->id}/connections{$path}";

    // Invite at C3, accept at G1 sharing the given units; the client's view of the connection.
    $this->connect = function (array $units) {
        $code = $this->asToken($this->provider)->postJson(($this->api)($this->w->c3, '/invitations'), ['note' => 'Rider Group'])->assertCreated()->json('data.code');

        return $this->asToken($this->client)->postJson(($this->api)($this->w->g1, '/accept'), ['code' => $code, 'unit_ids' => $units])->assertCreated()->json('data');
    };
    $this->send = fn (Connection $connection, Organization $from, Organization $to, array $payload = ['title' => 'Renewal'], string $op = 'op-1', array $files = [], string $kind = 'crm.fixture_note') => app(Connections::class)
        ->send($connection, $kind, $from, $to->id, $payload, $files, $op, $this->providerUser);
});

it('invites by a code shown once, shows who invites, and connects sharing the chosen factories', function () {
    $made = $this->asToken($this->provider)->postJson(($this->api)($this->w->c3, '/invitations'), ['note' => 'Rider Group'])
        ->assertCreated()->assertJsonPath('data.status', 'invited')->assertJsonPath('data.side', 'provider')->json('data');
    expect($made['code'])->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/')
        ->and(Connection::query()->sole()->code_hash)->toBe(hash('sha256', str_replace('-', '', $made['code'])));

    // Before accepting: who invites, and the client's own companies and branches to choose from.
    $preview = $this->asToken($this->client)->postJson(($this->api)($this->w->g1, '/preview'), ['code' => strtolower($made['code'])])->assertOk()->json('data');
    expect($preview['provider'])->toBe('C3')
        ->and(array_column($preview['units'], 'id'))->toEqualCanonicalizing([$this->w->c1->id, $this->w->b1->id, $this->w->c2->id, $this->w->b2->id]);

    $accepted = $this->asToken($this->client)->postJson(($this->api)($this->w->g1, '/accept'), ['code' => $made['code'], 'unit_ids' => [$this->w->c1->id]])
        ->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.side', 'client')->assertJsonPath('data.other', 'C3')
        // The provider's own label for the client is never shown to the client.
        ->assertJsonPath('data.note', null)->json('data');

    // The provider sees the client by name, and the shared company with its branch: nothing else.
    $seen = $this->asToken($this->provider)->getJson(($this->api)($this->w->c3, "/{$accepted['id']}"))->assertOk()->json('data');
    expect($seen)->toMatchArray(['side' => 'provider', 'other' => 'G1', 'note' => 'Rider Group'])
        ->and(array_column($seen['units'], 'name'))->toBe(['C1', 'B1']);
    $connection = Connection::query()->sole();
    expect(app(Connections::class)->covers($connection, $this->w->b1->id))->toBeTrue()
        ->and(app(Connections::class)->covers($connection, $this->w->d1->id))->toBeTrue()
        ->and(app(Connections::class)->covers($connection, $this->w->c2->id))->toBeFalse()
        ->and($connection->code_hash)->toBeNull();

    // Used once: never again; audited on both sides.
    $this->asToken($this->client)->postJson(($this->api)($this->w->g1, '/accept'), ['code' => $made['code'], 'unit_ids' => [$this->w->c1->id]])->assertNotFound();
    expect(AuditLog::query()->where('action', 'business_network.accepted')->pluck('organization_id')->all())->toEqualCanonicalizing([$this->w->g1->id, $this->w->c3->id]);
});

it('refuses wrong codes, the wrong sides and a second connection', function () {
    $this->asToken($this->client)->postJson(($this->api)($this->w->g1, '/preview'), ['code' => 'ABCD-EFGH-JKMN'])->assertNotFound()->assertJsonPath('code', 'code_not_found');
    // Only a company invites; a branch does not accept.
    $groupOwner = orgToken($this->clientUser, $this->w->g1);
    $this->asToken($groupOwner)->postJson(($this->api)($this->w->g1, '/invitations'), [])->assertStatus(422)->assertJsonPath('code', 'provider_must_be_company');
    $code = $this->asToken($this->provider)->postJson(($this->api)($this->w->c3, '/invitations'), [])->json('data.code');
    $this->asToken(orgToken(createMember($this->w->b1), $this->w->b1))->postJson(($this->api)($this->w->b1, '/accept'), ['code' => $code, 'unit_ids' => [$this->w->b1->id]])
        ->assertStatus(422)->assertJsonPath('code', 'client_must_be_group_or_company');
    // Only its own open companies and branches.
    $this->asToken($this->client)->postJson(($this->api)($this->w->g1, '/accept'), ['code' => $code, 'unit_ids' => [$this->w->c3->id]])
        ->assertStatus(422)->assertJsonPath('code', 'unit_not_yours');
    $this->asToken($this->client)->postJson(($this->api)($this->w->g1, '/accept'), ['code' => $code, 'unit_ids' => [$this->w->d1->id]])
        ->assertStatus(422)->assertJsonPath('code', 'unit_not_yours');

    // Its own tree: never.
    $own = $this->asToken(orgToken(createMember($this->w->c1), $this->w->c1))->postJson(($this->api)($this->w->c1, '/invitations'), [])->json('data.code');
    $this->asToken($this->client)->postJson(($this->api)($this->w->g1, '/accept'), ['code' => $own, 'unit_ids' => [$this->w->c2->id]])
        ->assertStatus(422)->assertJsonPath('code', 'own_organization');

    ($this->connect)([$this->w->c1->id]);
    $again = $this->asToken($this->provider)->postJson(($this->api)($this->w->c3, '/invitations'), [])->json('data.code');
    $this->asToken($this->client)->postJson(($this->api)($this->w->g1, '/accept'), ['code' => $again, 'unit_ids' => [$this->w->c2->id]])
        ->assertStatus(409)->assertJsonPath('code', 'already_connected');

    // Expired after the rule's days.
    $late = $this->asToken($this->provider)->postJson(($this->api)($this->w->c3, '/invitations'), [])->json('data.code');
    $this->travel(15)->days();
    $this->client = orgToken($this->clientUser, $this->w->g1);
    $this->asToken($this->client)->postJson(($this->api)($this->w->g1, '/preview'), ['code' => $late])->assertStatus(410)->assertJsonPath('code', 'code_closed');
});

it('delivers messages into the receiver\'s own data, once per op_id, only to shared factories', function () {
    ($this->connect)([$this->w->c1->id]);
    $connection = Connection::query()->sole();

    $message = ($this->send)($connection, $this->w->c3, $this->w->b1);
    expect($message->fresh()->status)->toBe(ConnectionMessage::DELIVERED)
        ->and($message->fresh()->getRawOriginal('payload'))->toBeNull()
        ->and(FixtureConnectionNote::$received)->toHaveCount(1)
        ->and(FixtureConnectionNote::$received[0])->toMatchArray(['to' => $this->w->b1->id, 'from' => 'C3'])
        ->and(TenantNote::inTenantOf($this->w->b1)->withoutGlobalScope(OrganizationScope::class)->where('organization_id', $this->w->b1->id)->count())->toBe(1);

    // The same op_id again is the same message: nothing new arrives.
    expect(($this->send)($connection, $this->w->c3, $this->w->b1)->id)->toBe($message->id)
        ->and(FixtureConnectionNote::$received)->toHaveCount(1);

    // Not shared: refused before anything is stored.
    expect(fn () => ($this->send)($connection, $this->w->c3, $this->w->c2, op: 'op-2'))->toThrow(ConnectionException::class);
    // The client answers from a shared unit to the provider company only.
    ($this->send)($connection, $this->w->c1, $this->w->c3, ['title' => 'Answer'], 'op-3', [], 'crm.fixture_reply');
    expect(FixtureConnectionNote::$received[1])->toMatchArray(['to' => $this->w->c3->id, 'from' => 'C1']);
    expect(fn () => ($this->send)($connection, $this->w->c2, $this->w->c3, [], 'op-4', [], 'crm.fixture_reply'))->toThrow(ConnectionException::class);

    // The sides see envelopes only, from their own side's point of view.
    $client = $this->asToken($this->client)->getJson(($this->api)($this->w->g1, "/{$connection->id}/messages"))->assertOk()->json('data');
    expect($client)->toHaveCount(2)
        ->and(collect($client)->firstWhere('kind', 'crm.fixture_note'))->toMatchArray(['sent' => false, 'from' => 'C3', 'to' => 'B1', 'status' => 'delivered'])
        ->and(json_encode($client))->not->toContain('Renewal');
});

it('delivers into a client\'s own database', function () {
    $database = dedicatedTenantDatabase();
    placeClient($this->w->g1);
    ($this->connect)([$this->w->c1->id]);

    ($this->send)(Connection::query()->sole(), $this->w->c3, $this->w->b1);
    expect(FixtureConnectionNote::$received[0]['database'])->toBe($database)
        ->and(TenantNote::inTenantOf($this->w->b1)->withoutGlobalScope(OrganizationScope::class)->where('organization_id', $this->w->b1->id)->count())->toBe(1);
});

it('copies files unchanged into the receiver\'s storage, within the size rule', function () {
    Storage::fake('local');
    Storage::disk('local')->put('provider/reports/final.pdf', '%PDF-1.4 final report');
    ($this->connect)([$this->w->c1->id]);
    $connection = Connection::query()->sole();

    ($this->send)($connection, $this->w->c3, $this->w->c1, files: [['path' => 'provider/reports/final.pdf', 'name' => 'Final report.pdf', 'mime' => 'application/pdf']]);
    $file = FixtureConnectionNote::$received[0]['files'][0];
    expect($file['path'])->toStartWith('connections/'.$this->w->g1->root_id.'/')
        ->and(Storage::disk('local')->get($file['path']))->toBe('%PDF-1.4 final report')
        ->and($file['sha256'])->toBe(hash('sha256', '%PDF-1.4 final report'));

    Storage::disk('local')->put('provider/reports/big.pdf', str_repeat('x', 2 * 1024 * 1024));
    platformRule('business_network.max_file_mb', 1);
    expect(fn () => ($this->send)($connection, $this->w->c3, $this->w->c1, op: 'op-big', files: [['path' => 'provider/reports/big.pdf', 'name' => 'big.pdf', 'mime' => 'application/pdf']]))
        ->toThrow(ConnectionException::class);
});

it('stops everything while paused or ended; only the side that paused resumes', function () {
    $view = ($this->connect)([$this->w->c1->id]);
    $connection = Connection::query()->sole();

    $paused = $this->asToken($this->client)->postJson(($this->api)($this->w->g1, "/{$view['id']}/pause"), ['base_version' => $view['version']])->assertOk()->json('data');
    expect(fn () => ($this->send)($connection->fresh(), $this->w->c3, $this->w->b1))->toThrow(ConnectionException::class);
    $this->asToken($this->provider)->postJson(($this->api)($this->w->c3, "/{$view['id']}/resume"), ['base_version' => $paused['version']])->assertForbidden();
    $resumed = $this->asToken($this->client)->postJson(($this->api)($this->w->g1, "/{$view['id']}/resume"), ['base_version' => $paused['version']])->assertOk()->json('data');

    // Queued, then ended before it went out: never delivered, payload wiped.
    Queue::fake();
    $message = ($this->send)($connection->fresh(), $this->w->c3, $this->w->b1);
    $this->asToken($this->provider)->postJson(($this->api)($this->w->c3, "/{$view['id']}/end"), ['base_version' => $resumed['version']])->assertStatus(422);
    $this->asToken($this->provider)->postJson(($this->api)($this->w->c3, "/{$view['id']}/end"), ['base_version' => $resumed['version'], 'reason' => 'Contract over'])
        ->assertOk()->assertJsonPath('data.status', 'ended');
    app()->call([new DeliverConnectionMessage($message->id), 'handle']);
    expect($message->fresh())->status->toBe(ConnectionMessage::FAILED)->error->toBe('connection_closed')
        ->and($message->fresh()->getRawOriginal('payload'))->toBeNull()
        ->and(FixtureConnectionNote::$received)->toBe([]);

    $this->asToken($this->client)->getJson(($this->api)($this->w->g1, "/{$view['id']}"))->assertOk()
        ->assertJsonPath('data.status', 'ended')->assertJsonPath('data.end_reason', 'Contract over')->assertJsonPath('data.can.end', false);
});

it('lets only the client change the shared factories, and the provider loses what is unshared', function () {
    $view = ($this->connect)([$this->w->c1->id]);
    $connection = Connection::query()->sole();

    $this->asToken($this->provider)->putJson(($this->api)($this->w->c3, "/{$view['id']}/units"), ['base_version' => $view['version'], 'unit_ids' => [$this->w->c2->id]])->assertForbidden();
    $changed = $this->asToken($this->client)->putJson(($this->api)($this->w->g1, "/{$view['id']}/units"), ['base_version' => $view['version'], 'unit_ids' => [$this->w->b2->id]])
        ->assertOk()->json('data');
    expect($changed['shared_ids'])->toBe([$this->w->b2->id])
        ->and(app(Connections::class)->covers($connection, $this->w->b1->id))->toBeFalse()
        ->and(app(Connections::class)->covers($connection, $this->w->b2->id))->toBeTrue();
    // A stale version is refused.
    $this->asToken($this->client)->putJson(($this->api)($this->w->g1, "/{$view['id']}/units"), ['base_version' => $view['version'], 'unit_ids' => [$this->w->c1->id]])
        ->assertStatus(409)->assertJsonPath('code', 'version_conflict');
});

it('fails a message the receiving side cannot take, and tells the sender why', function () {
    ($this->connect)([$this->w->c1->id]);
    $connection = Connection::query()->sole();

    // The receiving module throws: out of tries, failed and wiped.
    expect(fn () => ($this->send)($connection, $this->w->c3, $this->w->b1, ['fail' => true]))->toThrow(RuntimeException::class);
    expect(ConnectionMessage::query()->where('op_id', 'op-1')->sole())->status->toBe(ConnectionMessage::FAILED)->error->toBe('delivery_failed');

    // The module that receives it is off at the client: given up, never stored.
    toggles()->disable($this->w->g1, 'crm', 'Test');
    ($this->send)($connection, $this->w->c3, $this->w->b1, op: 'op-2');
    expect(ConnectionMessage::query()->where('op_id', 'op-2')->sole())->status->toBe(ConnectionMessage::FAILED)->error->toBe('kind_off')
        ->and(FixtureConnectionNote::$received)->toBe([])
        ->and(AuditLog::query()->where('action', 'business_network.message_failed')->where('organization_id', $this->w->c3->id)->count())->toBe(2);
});

it('keeps connections to their two sides, to people allowed, and to the module being on', function () {
    $view = ($this->connect)([$this->w->c1->id]);

    // A viewer sees, never acts.
    $viewer = orgToken(staffWithRoles($this->w->g1, makeRole($this->w->g1, ['business_network.view'], 'Viewer')), $this->w->g1);
    $this->asToken($viewer)->getJson(($this->api)($this->w->g1))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can.pause', false);
    $this->asToken($viewer)->postJson(($this->api)($this->w->g1, "/{$view['id']}/pause"), ['base_version' => $view['version']])->assertForbidden();
    $nobody = orgToken(staffWithRoles($this->w->g1, makeRole($this->w->g1, ['hrm.view'], 'Other')), $this->w->g1);
    $this->asToken($nobody)->getJson(($this->api)($this->w->g1))->assertForbidden();

    // A third client never finds it.
    $third = orgToken(createMember($this->w->c4), $this->w->c4);
    $this->asToken($third)->getJson(($this->api)($this->w->c4))->assertOk()->assertJsonCount(0, 'data');
    $this->asToken($third)->getJson(($this->api)($this->w->c4, "/{$view['id']}"))->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->asToken($third)->getJson(($this->api)($this->w->g1, "/{$view['id']}"))->assertNotFound();

    // Partner support staff, even with the client's approval, never see the other side.
    $staff = createPartnerStaff($this->w->partnerA, PartnerUserRole::Support);
    $grantId = $this->asToken(partnerToken($staff, $this->w->partnerA))->postJson('http://localhost/api/partner/support-grants', [
        'organization_id' => $this->w->g1->id, 'reason' => 'Checking the shared factories with the client.', 'severity' => 'normal', 'minutes' => 60,
    ])->assertCreated()->json('data.id');
    $this->asToken($this->client)->postJson("http://localhost/api/organizations/{$this->w->g1->id}/support-grants/{$grantId}/approve", ['reason' => 'Go ahead'])->assertOk();
    $this->withoutToken();
    app('auth')->forgetGuards();
    spaSession($this, $staff, partner: $this->w->partnerA);
    $this->postJson('/session/context', ['support_grant_id' => $grantId])->assertOk();
    $this->getJson(($this->api)($this->w->g1))->assertForbidden()->assertJsonPath('code', 'not_during_support');
    $this->withoutHeader('Origin');
    $this->flushSession();

    toggles()->disable($this->w->g1, 'business_network', 'Test');
    $this->asToken($this->client)->getJson(($this->api)($this->w->g1))->assertForbidden();
});

it('connects with another provider\'s client only while both allow it', function () {
    // C4 belongs to partner B; G1 to partner A.
    $outsider = orgToken(createMember($this->w->c4), $this->w->c4);
    $code = $this->asToken($outsider)->postJson(($this->api)($this->w->c4, '/invitations'), [])->assertCreated()->json('data.code');

    partnerRule($this->w->partnerA, 'business_network.allow_other_partners', false);
    $this->asToken($this->client)->postJson(($this->api)($this->w->g1, '/accept'), ['code' => $code, 'unit_ids' => [$this->w->c1->id]])
        ->assertStatus(422)->assertJsonPath('code', 'other_partner');

    partnerRule($this->w->partnerA, 'business_network.allow_other_partners', true);
    $this->asToken($this->client)->postJson(($this->api)($this->w->g1, '/accept'), ['code' => $code, 'unit_ids' => [$this->w->c1->id]])
        ->assertCreated()->assertJsonPath('data.other', 'C4');
});
