<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Connections\Jobs\DeliverConnectionMessage;
use App\Platform\Connections\Models\ConnectionMessage;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Compliance\Connections\RequestReplyHandler;
use Modules\Compliance\Models\ProposedResult;
use Modules\Compliance\Models\RenewalRequest;
use Modules\Compliance\Services\Reminders;
use Modules\Projects\Models\IncomingRequest;

/*
 * CMP-2: renewals with the providers. The client (group G1: C1 > B1 and
 * C2 > B2) keeps its audit register; its provider Apex (company C3) is
 * connected on the platform and shares C1 (with B1). A renewal request
 * goes to Apex over the connection (Apex's Projects receives it: a fixture
 * here); Apex's answers and audit results come back and change the
 * register only when the client accepts them (or the rule says at once).
 * A provider not on the platform gets an email the client sends itself.
 */

beforeEach(function () {
    // Wednesday 28 October 2026, 20:00 UTC: already Thursday the 29th in Dhaka.
    $this->travelTo(CarbonImmutable::parse('2026-10-28 20:00:00', 'UTC'));
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        setPlan($group, 'business');
        // Apex's side receives requests in its Projects.
        foreach (['compliance', 'business_network', 'crm', 'projects'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    $this->received = fn () => IncomingRequest::query()->withoutGlobalScope(OrganizationScope::class)->get();

    $person = function (Organization $at, array $permissions, string $name) {
        $user = createMember($at, MembershipType::Staff, AccessScope::Descendants);
        giveRoles($user, $at, makeRole($at, $permissions, $name));

        return $user;
    };
    $this->hracUser = $person($this->w->c1, ['compliance.view', 'compliance.manage', 'compliance.set_expiry', 'compliance.request', 'compliance.confirm'], 'HRAC C1');
    $this->hrac = orgToken($this->hracUser, $this->w->c1);
    $this->officer = orgToken($person($this->w->c1, ['compliance.view', 'compliance.manage'], 'Officer'), $this->w->c1);
    $this->hracC2 = orgToken($person($this->w->c2, ['compliance.view', 'compliance.manage', 'compliance.set_expiry', 'compliance.request'], 'HRAC C2'), $this->w->c2);
    $this->groupOwner = orgToken(createMember($this->w->g1), $this->w->g1);
    $this->providerUser = createMember($this->w->c3);
    $this->api = fn (string $path, ?Organization $at = null) => '/api/organizations/'.($at ?? $this->w->c1)->id."/compliance/{$path}";

    // Called first by each test (after placing the client in its own database, when a test does).
    $this->prepare = function () {
        // Apex invites, G1 accepts sharing C1 (and so B1).
        $code = $this->asToken(orgToken($this->providerUser, $this->w->c3))->postJson("/api/organizations/{$this->w->c3->id}/connections/invitations", [])->assertCreated()->json('data.code');
        $this->connectionId = $this->asToken($this->groupOwner)->postJson("/api/organizations/{$this->w->g1->id}/connections/accept", ['code' => $code, 'unit_ids' => [$this->w->c1->id]])
            ->assertCreated()->json('data.id');

        // The group's lists: the usual services, Apex linked to its connection, a provider not on the platform.
        $this->asToken($this->groupOwner)->postJson(($this->api)('lists/services/defaults', $this->w->g1))->assertOk();
        $this->asToken($this->groupOwner)->postJson(($this->api)('lists/types/defaults', $this->w->g1))->assertOk();
        $this->apex = $this->asToken($this->groupOwner)->postJson(($this->api)('lists/providers', $this->w->g1), ['name' => 'Apex', 'connection_id' => $this->connectionId])
            ->assertCreated()->assertJsonPath('data.on_platform', true)->json('data.id');
        $this->local = $this->asToken($this->groupOwner)->postJson(($this->api)('lists/providers', $this->w->g1), ['name' => 'Local Certs', 'email' => 'desk@localcerts.test'])
            ->assertCreated()->json('data.id');
        $this->services = collect($this->asToken($this->groupOwner)->getJson(($this->api)('lists/services', $this->w->g1))->json('data'))->pluck('id', 'code');
    };

    // A register line by the factory's company people.
    $this->line = function (Organization $factory, string $code, string $provider, ?string $expires = '2026-12-01', ?string $token = null) {
        $services = $this->services;
        return $this->asToken($token ?? $this->hrac)->postJson(($this->api)('audits', str_starts_with($factory->path, $this->w->c2->path) ? $this->w->c2 : $this->w->c1), [
            'unit_id' => $factory->id, 'service_id' => $services[$code], 'provider_id' => $provider, 'last_audit_on' => '2025-12-02', 'expires_on' => $expires,
        ])->assertCreated()->json('data');
    };
    // What Apex's Projects sends back (PRJ-1): the platform's own delivery, as after a send.
    $this->fromApex = function (string $kind, Organization $to, array $payload, array $files = []) {
        $message = new ConnectionMessage;
        $message->forceFill([
            'connection_id' => $this->connectionId, 'direction' => ConnectionMessage::TO_CLIENT, 'kind' => $kind,
            'from_unit_id' => $this->w->c3->id, 'to_unit_id' => $to->id, 'sent_by' => $this->providerUser->id,
            'op_id' => 'apex-'.Str::ulid(), 'payload' => $payload, 'files' => $files, 'status' => ConnectionMessage::QUEUED,
        ])->save();
        app()->forgetScopedInstances();
        app()->call([new DeliverConnectionMessage($message->id), 'handle']);

        return $message->fresh();
    };
});

it('sends a renewal request to a connected provider and takes its answers', function () {
    ($this->prepare)();
    $line = ($this->line)($this->w->b1, 'BSCI', $this->apex);
    expect($line['provider'])->toMatchArray(['on_platform' => true, 'receives_requests' => true]);
    $made = $this->asToken($this->hrac)->postJson(($this->api)("audits/{$line['id']}/renewal"), ['wanted_by' => '2026-11-20', 'note' => 'Before the buyer visit'])
        ->assertCreated()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.mail', null)->assertJsonPath('data.delivery', 'delivered')->json('data');

    // Apex received it in its own Projects, from the factory, with what it needs.
    $inbox = ($this->received)();
    expect($inbox)->toHaveCount(1)
        ->and($inbox[0])->toMatchArray([
            'organization_id' => $this->w->c3->id, 'factory_name' => 'B1', 'client_name' => 'G1', 'client_request_id' => $made['id'],
            'service_code' => 'BSCI', 'note' => 'Before the buyer visit', 'requested_by' => $this->hracUser->name, 'status' => 'new',
        ])
        ->and($inbox[0]->expires_on->toDateString())->toBe('2026-12-01')
        ->and($inbox[0]->wanted_by->toDateString())->toBe('2026-11-20');
    // One open request per line.
    $this->asToken($this->hrac)->postJson(($this->api)("audits/{$line['id']}/renewal"), [])->assertStatus(409)->assertJsonPath('code', 'request_open');
    $this->asToken($this->hrac)->getJson(($this->api)('register'))->assertOk()->assertJsonPath('data.0.renewal.status', 'sent');

    // Apex accepts with a planned day: the request shows it, the requesters are told.
    $reply = ($this->fromApex)('projects.request_reply', $this->w->b1, ['request_id' => $made['id'], 'status' => 'accepted', 'planned_on' => '2026-11-15', 'note' => 'Auditor booked']);
    expect($reply->status)->toBe(ConnectionMessage::DELIVERED);
    $request = RenewalRequest::query()->withoutGlobalScope(OrganizationScope::class)->sole();
    expect($request->status)->toBe('accepted')->and($request->planned_on->toDateString())->toBe('2026-11-15')->and($request->reply_note)->toBe('Auditor booked')
        ->and(NotificationDelivery::query()->where('notification_key', 'compliance.renewal_answered')->where('user_id', $this->hracUser->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'compliance.renewal_answered')->where('organization_id', $this->w->b1->id)->exists())->toBeTrue();

    // The same message again changes nothing; an answer over another connection is not this request's.
    $handler = app(RequestReplyHandler::class);
    $again = new ConnectionDelivery($reply->id, $this->connectionId, 'projects.request_reply', $this->w->b1, $this->w->c3->id, 'C3', ['request_id' => $made['id'], 'status' => 'declined'], []);
    expect($handler->receive($again))->toBe($request->id)->and($request->fresh()->status)->toBe('accepted');
    $stranger = new ConnectionDelivery((string) Str::ulid(), (string) Str::ulid(), 'projects.request_reply', $this->w->b1, $this->w->c4->id, 'C4', ['request_id' => $made['id'], 'status' => 'declined'], []);
    expect($handler->receive($stranger))->toBeNull()->and($request->fresh()->status)->toBe('accepted');

    $this->asToken($this->hrac)->getJson(($this->api)("audits/{$line['id']}"))->assertOk()
        ->assertJsonPath('data.renewal.status', 'accepted')->assertJsonPath('data.renewal.planned_on', '2026-11-15')->assertJsonPath('data.renewal.can.cancel', true);
});

it('notes a request for a provider not on the platform, with the email ready, and cancels it', function () {
    ($this->prepare)();
    $line = ($this->line)($this->w->c1, 'WRAP', $this->local);
    $made = $this->asToken($this->hrac)->postJson(($this->api)("audits/{$line['id']}/renewal"), ['wanted_by' => '2026-11-25'])
        ->assertCreated()->assertJsonPath('data.status', 'recorded')->assertJsonPath('data.mail.email', 'desk@localcerts.test')->json('data');
    expect($made['mail']['subject'])->toContain('WRAP')->toContain('C1')
        ->and($made['mail']['body'])->toContain('2026-12-01')->toContain('2026-11-25')
        ->and(($this->received)())->toHaveCount(0);

    // Not past days, nothing unknown.
    $this->asToken($this->hrac)->postJson(($this->api)("audits/{$line['id']}/renewal"), ['wanted_by' => '2026-10-01'])->assertUnprocessable();

    $this->asToken($this->hrac)->postJson(($this->api)("renewals/{$made['id']}/cancel"), ['base_version' => 1, 'reason' => 'Done by phone'])
        ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.open', false);
    $this->asToken($this->hrac)->postJson(($this->api)("renewals/{$made['id']}/cancel"), ['base_version' => 2])->assertStatus(409)->assertJsonPath('code', 'request_closed');
    // A new one may be made.
    $this->asToken($this->hrac)->postJson(($this->api)("audits/{$line['id']}/renewal"), [])->assertCreated();
    $this->asToken($this->hrac)->getJson(($this->api)('renewals'))->assertOk()->assertJsonCount(2, 'data.requests');
    $this->asToken($this->hrac)->getJson(($this->api)('renewals').'?open=1')->assertOk()->assertJsonCount(1, 'data.requests');
});

it('keeps a provider\'s result proposed and changes the register only when accepted', function () {
    ($this->prepare)();
    Storage::fake('local');
    Storage::disk('local')->put('apex/certificates/wrap.pdf', '%PDF-1.4 WRAP certificate');
    $line = ($this->line)($this->w->c1, 'WRAP', $this->apex, '2026-11-10');
    $request = $this->asToken($this->hrac)->postJson(($this->api)("audits/{$line['id']}/renewal"), [])->assertCreated()->json('data');

    ($this->fromApex)('projects.audit_result', $this->w->c1, [
        'request_id' => $request['id'], 'service' => ['code' => 'WRAP', 'name' => 'WRAP'], 'audit_type' => 'renewal',
        'last_audit_on' => '2026-11-05', 'expires_on' => '2027-11-04', 'result' => 'Gold', 'certificate_no' => 'WR-2026-77', 'note' => 'No findings',
    ], [['path' => 'apex/certificates/wrap.pdf', 'name' => 'WRAP certificate.pdf', 'mime' => 'application/pdf', 'size' => 25, 'sha256' => hash('sha256', '%PDF-1.4 WRAP certificate')]]);

    // Waiting: the register is as it was; the people who accept are told.
    expect(NotificationDelivery::query()->where('notification_key', 'compliance.result_received')->where('user_id', $this->hracUser->id)->exists())->toBeTrue();
    $this->asToken($this->hrac)->getJson(($this->api)('register'))->assertOk()
        ->assertJsonPath('data.0.expires_on', '2026-11-10')->assertJsonPath('data.0.results_pending', 1);
    $detail = $this->asToken($this->hrac)->getJson(($this->api)("audits/{$line['id']}"))->assertOk()->json('data');
    expect($detail['results'])->toHaveCount(1)
        ->and($detail['results'][0])->toMatchArray(['expires_on' => '2027-11-04', 'result' => 'Gold', 'from' => 'C3', 'status' => 'pending'])
        ->and($detail['results'][0]['current']['expires_on'])->toBe('2026-11-10')
        ->and($detail['results'][0]['can']['decide'])->toBeTrue();
    // The certificate opens through its short-lived link only.
    $url = $detail['results'][0]['files'][0]['url'];
    expect($this->get($url)->assertOk()->streamedContent())->toBe('%PDF-1.4 WRAP certificate');
    $this->get(Str::before($url, '?'))->assertForbidden();

    // Someone without compliance.confirm cannot accept it.
    $result = $detail['results'][0];
    $this->asToken($this->officer)->postJson(($this->api)("results/{$result['id']}/apply"), ['base_version' => 1])->assertForbidden();

    $this->asToken($this->hrac)->postJson(($this->api)("results/{$result['id']}/apply"), ['base_version' => 1])->assertOk()->assertJsonPath('data.status', 'applied');
    $after = $this->asToken($this->hrac)->getJson(($this->api)("audits/{$line['id']}"))->assertOk()->json('data');
    expect($after)->toMatchArray(['last_audit_on' => '2026-11-05', 'expires_on' => '2027-11-04', 'result' => 'Gold', 'certificate_no' => 'WR-2026-77', 'state' => 'valid'])
        ->and($after['audit_type']['name'])->toBe('Renewal')
        ->and(collect($after['history'])->pluck('kind'))->toContain('from_provider')
        ->and($after['renewal']['status'])->toBe('done')
        ->and($after['results'])->toBe([]);
    $this->asToken($this->hrac)->postJson(($this->api)("results/{$result['id']}/apply"), ['base_version' => 2])->assertStatus(409)->assertJsonPath('code', 'result_decided');
});

it('adds the line from a result when it is missing, and rejects one with a reason', function () {
    ($this->prepare)();
    ($this->fromApex)('projects.audit_result', $this->w->b1, ['service' => ['code' => 'ISO9001', 'name' => 'ISO 9001'], 'last_audit_on' => '2026-10-01', 'expires_on' => '2029-09-30']);
    ($this->fromApex)('projects.audit_result', $this->w->b1, ['service' => ['code' => 'SA8000', 'name' => 'SA 8000'], 'expires_on' => '2027-01-31']);
    // A day that does not exist is not taken.
    ($this->fromApex)('projects.audit_result', $this->w->c1, ['service' => ['code' => 'WRAP'], 'expires_on' => '2027-02-31']);

    $results = collect($this->asToken($this->hrac)->getJson(($this->api)('renewals'))->assertOk()->json('data.results'))->keyBy('service.code');
    expect($results)->toHaveCount(3)
        ->and($results['ISO9001']['current'])->toBeNull()
        ->and($results['WRAP']['expires_on'])->toBeNull();

    $this->asToken($this->hrac)->postJson(($this->api)("results/{$results['ISO9001']['id']}/apply"), ['base_version' => 1])->assertOk();
    $line = collect($this->asToken($this->hrac)->getJson(($this->api)('register'))->json('data'))->firstWhere('service.code', 'ISO9001');
    expect($line)->toMatchArray(['expires_on' => '2029-09-30'])->and($line['factory']['id'])->toBe($this->w->b1->id);

    // Not in the lists: the client adds the service first.
    $this->asToken($this->hrac)->postJson(($this->api)("results/{$results['SA8000']['id']}/apply"), ['base_version' => 1])
        ->assertUnprocessable()->assertJsonPath('code', 'result_service_unknown');
    $this->asToken($this->hrac)->postJson(($this->api)("results/{$results['SA8000']['id']}/reject"), ['base_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->asToken($this->hrac)->postJson(($this->api)("results/{$results['SA8000']['id']}/reject"), ['base_version' => 1, 'reason' => 'Not our audit'])
        ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.reject_reason', 'Not our audit');
    expect(AuditLog::query()->where('action', 'compliance.result_rejected')->exists())->toBeTrue();
});

it('takes results in at once and sends requests by itself when the rules say so', function () {
    ($this->prepare)();
    trustedOrgRule($this->w->g1, 'compliance.auto_apply_results', true);
    $line = ($this->line)($this->w->c1, 'WRAP', $this->apex, '2026-11-10');
    ($this->fromApex)('projects.audit_result', $this->w->c1, ['service' => ['code' => 'WRAP'], 'expires_on' => '2027-11-09', 'result' => 'A']);
    $applied = ProposedResult::query()->withoutGlobalScope(OrganizationScope::class)->sole();
    expect($applied->status)->toBe('applied')->and($applied->decided_by)->toBeNull();
    $this->asToken($this->hrac)->getJson(($this->api)("audits/{$line['id']}"))->assertJsonPath('data.expires_on', '2027-11-09');

    // Requests by themselves: connected providers only, within the rule's days, once.
    trustedOrgRule($this->w->g1, 'compliance.auto_request', true);
    ($this->line)($this->w->b1, 'BSCI', $this->apex, '2026-12-20');
    ($this->line)($this->w->b1, 'SMETA', $this->apex, '2027-06-30');
    ($this->line)($this->w->c1, 'HIGG_FEM', $this->local, '2026-11-20');
    app()->forgetScopedInstances();
    app(Reminders::class)->run();
    app()->forgetScopedInstances();
    app(Reminders::class)->run();
    expect(($this->received)())->toHaveCount(1)
        ->and(($this->received)()[0]->service_code)->toBe('BSCI')
        ->and(RenewalRequest::query()->withoutGlobalScope(OrganizationScope::class)->sole()->requested_by)->toBeNull();
});

it('shows the request as not delivered when the provider does not use Projects', function () {
    ($this->prepare)();
    toggles()->disable($this->w->g2, 'projects', 'Test');
    $line = ($this->line)($this->w->b1, 'BSCI', $this->apex);
    $this->asToken($this->hrac)->postJson(($this->api)("audits/{$line['id']}/renewal"), [])
        ->assertCreated()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.delivery', 'failed');
    expect(($this->received)())->toHaveCount(0);
    // The client sees it, and may cancel and email them instead.
    $this->asToken($this->hrac)->getJson(($this->api)("audits/{$line['id']}"))->assertJsonPath('data.renewal.delivery', 'failed');
});

it('keeps renewals to the right people, factories and tenants', function () {
    ($this->prepare)();
    $line = ($this->line)($this->w->c1, 'BSCI', $this->apex);
    // Without compliance.request; from the group (it reads, its companies write).
    $this->asToken($this->officer)->postJson(($this->api)("audits/{$line['id']}/renewal"), [])->assertForbidden();
    $this->asToken($this->groupOwner)->postJson(($this->api)("audits/{$line['id']}/renewal", $this->w->g1), [])->assertForbidden()->assertJsonPath('code', 'write_forbidden');
    $this->asToken($this->groupOwner)->getJson(($this->api)('renewals', $this->w->g1))->assertOk();

    // A factory the client did not share with Apex.
    $c2Line = ($this->line)($this->w->c2, 'BSCI', $this->apex, token: $this->hracC2);
    $this->asToken($this->hracC2)->postJson(($this->api)("audits/{$c2Line['id']}/renewal", $this->w->c2), [])->assertUnprocessable()->assertJsonPath('code', 'unit_not_covered');
    // A line without a provider.
    $bare = ($this->line)($this->w->b1, 'SMETA', $this->apex);
    $this->asToken($this->hrac)->patchJson(($this->api)("audits/{$bare['id']}"), ['base_version' => 1, 'provider_id' => null])->assertOk();
    $this->asToken($this->hrac)->postJson(($this->api)("audits/{$bare['id']}/renewal"), [])->assertUnprocessable()->assertJsonPath('code', 'no_provider');

    // Another tenant sees nothing, and cannot link a provider to G1's connection.
    $other = orgToken(createMember($this->w->g3), $this->w->g3);
    $this->asToken($other)->getJson(($this->api)('renewals'))->assertNotFound();
    $this->asToken($other)->postJson(($this->api)('lists/providers', $this->w->g3), ['name' => 'Apex', 'connection_id' => $this->connectionId])
        ->assertUnprocessable()->assertJsonPath('code', 'connection_not_yours');

    // Compliance off: no renewals, and what Apex sends is not delivered.
    toggles()->disable($this->w->g1, 'compliance', 'Test');
    $this->asToken($this->hrac)->getJson(($this->api)('renewals'))->assertForbidden();
    $message = ($this->fromApex)('projects.audit_result', $this->w->c1, ['service' => ['code' => 'BSCI'], 'expires_on' => '2027-11-30']);
    expect($message->status)->toBe(ConnectionMessage::FAILED)->and($message->error)->toBe('kind_off')
        ->and(ProposedResult::query()->withoutGlobalScope(OrganizationScope::class)->count())->toBe(0);
});

it('works for a client kept in its own database', function () {
    dedicatedTenantDatabase();
    placeClient($this->w->g1);
    ($this->prepare)();
    $line = ($this->line)($this->w->b1, 'BSCI', $this->apex);
    $request = $this->asToken($this->hrac)->postJson(($this->api)("audits/{$line['id']}/renewal"), [])->assertCreated()->json('data');
    ($this->fromApex)('projects.audit_result', $this->w->b1, ['request_id' => $request['id'], 'service' => ['code' => 'BSCI'], 'expires_on' => '2027-11-30']);

    expect(ProposedResult::inTenantOf($this->w->b1)->withoutGlobalScope(OrganizationScope::class)->where('status', 'pending')->count())->toBe(1);
    $result = $this->asToken($this->hrac)->getJson(($this->api)("audits/{$line['id']}"))->json('data.results.0');
    $this->asToken($this->hrac)->postJson(($this->api)("results/{$result['id']}/apply"), ['base_version' => 1])->assertOk();
    $this->asToken($this->hrac)->getJson(($this->api)("audits/{$line['id']}"))->assertJsonPath('data.expires_on', '2027-11-30')->assertJsonPath('data.renewal.status', 'done');
});
