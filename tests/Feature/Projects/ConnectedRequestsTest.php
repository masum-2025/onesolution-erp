<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Connections\Models\Connection;
use App\Platform\Connections\Services\ConnectionLinks;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Compliance\Models\ProposedResult;
use Modules\Compliance\Models\RenewalRequest;
use Modules\Projects\Models\IncomingRequest;

/*
 * The whole loop, over a business connection (optional for both modules):
 * the client's Compliance (group G1, factory B1) asks its provider Apex
 * (company C3) to renew an audit; Apex's Projects gets a new request,
 * accepts it (a project from the matching template, the planned day told
 * back), and when the work is done sends the result with the certificate;
 * the client accepts it into its register.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 20:00:00', 'UTC'));
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        setPlan($group, 'business');
        foreach (['compliance', 'business_network', 'crm', 'projects'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    Storage::fake('local');
    $person = function (Organization $at, array $permissions, string $name) {
        $user = createMember($at, MembershipType::Staff, AccessScope::Descendants);
        giveRoles($user, $at, makeRole($at, $permissions, $name));

        return $user;
    };
    $this->hracUser = $person($this->w->c1, ['compliance.view', 'compliance.manage', 'compliance.set_expiry', 'compliance.request', 'compliance.confirm'], 'HRAC');
    $this->apexUser = createMember($this->w->c3);
    $this->apexViewer = $person($this->w->c3, ['projects.view'], 'Apex viewer');
    $this->groupOwner = orgToken(createMember($this->w->g1), $this->w->g1);
    $this->client = fn (string $path, ?Organization $at = null) => '/api/organizations/'.($at ?? $this->w->c1)->id."/compliance/{$path}";
    $this->apex = fn (string $path = '') => "/api/organizations/{$this->w->c3->id}/projects".($path === '' ? '' : "/{$path}");
    $this->hrac = fn () => $this->asToken(orgToken($this->hracUser, $this->w->c1));
    $this->provider = fn () => $this->asToken(orgToken($this->apexUser, $this->w->c3));

    // Connected (C1 and its branch shared); Apex linked in the group's lists; Apex's template for BSCI.
    $this->prepare = function () {
        $code = ($this->provider)()->postJson("/api/organizations/{$this->w->c3->id}/connections/invitations", [])->assertCreated()->json('data.code');
        $this->connectionId = $this->asToken($this->groupOwner)->postJson("/api/organizations/{$this->w->g1->id}/connections/accept", ['code' => $code, 'unit_ids' => [$this->w->c1->id]])->assertCreated()->json('data.id');
        $this->asToken($this->groupOwner)->postJson(($this->client)('lists/services/defaults', $this->w->g1))->assertOk();
        $this->asToken($this->groupOwner)->postJson(($this->client)('lists/types/defaults', $this->w->g1))->assertOk();
        $this->apexProvider = $this->asToken($this->groupOwner)->postJson(($this->client)('lists/providers', $this->w->g1), ['name' => 'Apex', 'connection_id' => $this->connectionId])->assertCreated()->json('data.id');
        $this->services = collect($this->asToken($this->groupOwner)->getJson(($this->client)('lists/services', $this->w->g1))->json('data'))->pluck('id', 'code');
        $this->template = ($this->provider)()->postJson(($this->apex)('templates'), ['name' => ['en' => 'BSCI audit'], 'service' => 'bsci', 'steps' => [
            ['title' => ['en' => 'Pre-audit visit'], 'due_after_days' => 0], ['title' => ['en' => 'Audit day'], 'due_after_days' => 7],
        ]])->assertCreated()->json('data.id');
    };
    $this->ask = function (Organization $factory, string $code, string $expires = '2026-12-01') {
        $line = ($this->hrac)()->postJson(($this->client)('audits'), [
            'unit_id' => $factory->id, 'service_id' => $this->services[$code], 'provider_id' => $this->apexProvider, 'last_audit_on' => '2025-12-02', 'expires_on' => $expires,
        ])->assertCreated()->json('data');
        $request = ($this->hrac)()->postJson(($this->client)("audits/{$line['id']}/renewal"), ['wanted_by' => '2026-11-25', 'note' => 'Buyer visit in December'])
            ->assertCreated()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.delivery', 'delivered')->json('data');

        return [$line, $request];
    };
});

it('turns a client\'s renewal request into a project, and its result into the client\'s register', function () {
    ($this->prepare)();
    [$line, $request] = ($this->ask)($this->w->b1, 'BSCI');

    // Apex sees it, with the template that matches the service.
    $inbox = ($this->provider)()->getJson(($this->apex)('requests'))->assertOk()->json('data');
    expect($inbox)->toHaveCount(1)
        ->and($inbox[0])->toMatchArray(['client' => 'G1', 'factory' => 'B1', 'status' => 'new', 'template_id' => $this->template, 'expires_on' => '2026-12-01', 'wanted_by' => '2026-11-25'])
        ->and($inbox[0]['service']['code'])->toBe('BSCI')
        ->and($inbox[0]['can']['decide'])->toBeTrue();
    $attention = collect(($this->provider)()->getJson('/api/attention')->json('data'))->firstWhere('key', 'projects.requests');
    expect($attention['count'])->toBe(1);

    // Accepted: a project for G1 from the template; the client sees it accepted with the planned day.
    $made = ($this->provider)()->postJson(($this->apex)("requests/{$inbox[0]['id']}/accept"), ['starts_on' => '2026-11-10'])->assertCreated()->json('data');
    $project = ($this->provider)()->getJson(($this->apex)($made['project_id']))->assertOk()->json('data');
    expect($project)->toMatchArray(['connected' => true, 'service' => 'BSCI', 'starts_on' => '2026-11-10', 'due_on' => '2026-11-25', 'description' => 'Buyer visit in December'])
        ->and($project['client']['name'])->toBe('G1')
        ->and(collect($project['task_list'])->pluck('title')->all())->toBe(['Pre-audit visit', 'Audit day'])
        ->and($project['can']['send_result'])->toBeTrue();
    $renewal = RenewalRequest::query()->withoutGlobalScope(OrganizationScope::class)->findOrFail($request['id']);
    expect($renewal->status)->toBe('accepted')->and($renewal->planned_on->toDateString())->toBe('2026-11-10')->and($renewal->reply_note)->toBe($made['code']);
    ($this->provider)()->postJson(($this->apex)("requests/{$inbox[0]['id']}/accept"), ['starts_on' => '2026-11-10'])->assertStatus(409)->assertJsonPath('code', 'request_closed');

    // Done: the result with the certificate goes back, proposed until the client accepts it.
    ($this->provider)()->post(($this->apex)("{$made['project_id']}/result"), [
        'last_audit_on' => '2026-11-17', 'expires_on' => '2027-11-16', 'result' => 'Grade A', 'certificate_no' => 'BSCI-77', 'audit_type' => 'renewal',
        'file' => UploadedFile::fake()->create('BSCI certificate.pdf', 12, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();
    $proposed = ProposedResult::query()->withoutGlobalScope(OrganizationScope::class)->sole();
    expect($proposed)->toMatchArray(['organization_id' => $this->w->b1->id, 'service_code' => 'BSCI', 'result' => 'Grade A', 'certificate_no' => 'BSCI-77', 'status' => 'pending', 'from_name' => 'C3'])
        ->and($proposed->files)->toHaveCount(1)
        ->and($proposed->request_id)->toBe($request['id'])
        ->and($renewal->fresh()->status)->toBe('done');

    ($this->hrac)()->postJson(($this->client)("results/{$proposed->id}/apply"), ['base_version' => 1])->assertOk();
    ($this->hrac)()->getJson(($this->client)("audits/{$line['id']}"))->assertOk()
        ->assertJsonPath('data.expires_on', '2027-11-16')->assertJsonPath('data.certificate_no', 'BSCI-77')->assertJsonPath('data.audit_type.name', 'Renewal');
    expect(AuditLog::query()->whereIn('action', ['projects.request_received', 'projects.request_accepted', 'projects.result_sent'])->count())->toBe(3);
});

it('declines with a reason the client sees, and follows a request the client cancels', function () {
    ($this->prepare)();
    [, $wrap] = ($this->ask)($this->w->c1, 'WRAP');
    [, $smeta] = ($this->ask)($this->w->b1, 'SMETA');
    $inbox = collect(($this->provider)()->getJson(($this->apex)('requests'))->json('data'))->keyBy('service.code');

    ($this->provider)()->postJson(($this->apex)("requests/{$inbox['WRAP']['id']}/decline"), [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    ($this->provider)()->postJson(($this->apex)("requests/{$inbox['WRAP']['id']}/decline"), ['reason' => 'No WRAP auditor this month'])
        ->assertOk()->assertJsonPath('data.status', 'declined');
    $declined = RenewalRequest::query()->withoutGlobalScope(OrganizationScope::class)->findOrFail($wrap['id']);
    expect($declined->status)->toBe('declined')->and($declined->reply_note)->toBe('No WRAP auditor this month');

    // The client takes the other back: Apex sees it cancelled and can no longer accept it.
    ($this->hrac)()->postJson(($this->client)("renewals/{$smeta['id']}/cancel"), ['base_version' => $smeta['version'], 'reason' => 'Done by another body'])->assertOk();
    expect(IncomingRequest::query()->withoutGlobalScope(OrganizationScope::class)->findOrFail($inbox['SMETA']['id'])->status)->toBe('cancelled');
    ($this->provider)()->postJson(($this->apex)("requests/{$inbox['SMETA']['id']}/accept"), ['starts_on' => '2026-11-10'])->assertStatus(409)->assertJsonPath('code', 'request_closed');
});

it('keeps requests to the provider\'s managers, and results to connected projects', function () {
    ($this->prepare)();
    ($this->ask)($this->w->b1, 'BSCI');
    $incoming = ($this->provider)()->getJson(($this->apex)('requests'))->json('data.0');
    // A viewer of Apex reads but does not decide.
    $this->asToken(orgToken($this->apexViewer, $this->w->c3))->getJson(($this->apex)('requests'))->assertOk()->assertJsonPath('data.0.can.decide', false);
    $this->asToken(orgToken($this->apexViewer, $this->w->c3))->postJson(($this->apex)("requests/{$incoming['id']}/accept"), ['starts_on' => '2026-11-10'])->assertForbidden();
    // The client cannot see Apex's requests; another tenant neither.
    ($this->hrac)()->getJson(($this->apex)('requests'))->assertNotFound();
    $this->asToken(orgToken(createMember($this->w->c4), $this->w->c4))->getJson(($this->apex)('requests'))->assertNotFound();

    // A project of Apex's own (no connection) has no result to send.
    $own = ($this->provider)()->postJson(($this->apex)(), ['name' => 'Internal training', 'client_name' => 'Self', 'starts_on' => '2026-11-01'])->assertCreated()->json('data');
    expect($own['can']['send_result'])->toBeFalse();
    ($this->provider)()->postJson(($this->apex)("{$own['id']}/result"), ['result' => 'A'])->assertUnprocessable()->assertJsonPath('code', 'not_connected_project');

    // A paused connection: nothing goes either way.
    $connection = Connection::query()->findOrFail($this->connectionId);
    app(ConnectionLinks::class)->step($connection, $this->w->c3, 'pause', $connection->version, null, $this->apexUser);
    app(CurrentContext::class)->clear();
    ($this->provider)()->postJson(($this->apex)("requests/{$incoming['id']}/accept"), ['starts_on' => '2026-11-10'])->assertStatus(409)->assertJsonPath('code', 'connection_closed');
});

it('works for a client kept in its own database', function () {
    dedicatedTenantDatabase();
    placeClient($this->w->g1);
    ($this->prepare)();
    [$line] = ($this->ask)($this->w->b1, 'BSCI');
    $incoming = ($this->provider)()->getJson(($this->apex)('requests'))->json('data.0');
    $made = ($this->provider)()->postJson(($this->apex)("requests/{$incoming['id']}/accept"), ['starts_on' => '2026-11-10'])->assertCreated()->json('data');
    ($this->provider)()->postJson(($this->apex)("{$made['project_id']}/result"), ['expires_on' => '2027-11-16', 'result' => 'A'])->assertCreated();

    expect(ProposedResult::inTenantOf($this->w->b1)->withoutGlobalScope(OrganizationScope::class)->where('status', 'pending')->count())->toBe(1)
        ->and(RenewalRequest::inTenantOf($this->w->b1)->withoutGlobalScope(OrganizationScope::class)->sole()->status)->toBe('done');
});
