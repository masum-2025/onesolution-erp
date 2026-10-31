<?php

use App\Models\User;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Portal\PortalSubjects;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;

/*
 * A client following their projects in the company's portal: only the
 * projects of the clients (CRM contacts) linked to them, read only: status,
 * dates, tasks done and (by rule) task titles; never people, notes,
 * descriptions or money.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 06:00:00', 'UTC'));
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g3] as $group) {
        setPlan($group, 'business');
        foreach (['projects', 'crm', 'client_portal'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    $user = createMember($this->w->c1, MembershipType::Staff, AccessScope::Descendants);
    giveRoles($user, $this->w->c1, makeRole($this->w->c1, ['projects.view', 'projects.manage', 'projects.work', 'crm.view', 'crm.edit'], 'Manager'));
    $this->manager = orgToken($user, $this->w->c1);
    $this->managerId = $user->id;
    $this->api = fn (string $path = '') => "/api/organizations/{$this->w->c1->id}/projects".($path === '' ? '' : "/{$path}");
    $contact = fn (string $name) => $this->asToken($this->manager)->postJson("/api/organizations/{$this->w->c1->id}/crm/contacts", ['kind' => 'organization', 'name' => $name, 'tags' => ['client']])->assertCreated()->json('data.id');
    $this->rider = $contact('Rider Group');
    $this->rizwan = $contact('Rizwan Traders');
    $this->project = fn (string $client, string $name, array $data = []) => $this->asToken($this->manager)->postJson(($this->api)(), [
        'name' => $name, 'client_contact_id' => $client, 'starts_on' => '2026-11-01', 'description' => 'Internal: price was cut', ...$data,
    ])->assertCreated()->json('data');
    $this->task = fn (array $project, string $title, array $data = []) => $this->asToken($this->manager)->postJson(($this->api)("{$project['id']}/tasks"), ['title' => $title, ...$data])->assertCreated()->json('data');

    $this->audit = ($this->project)($this->rider, 'BSCI renewal', ['due_on' => '2026-11-05', 'manager_id' => $user->id]);
    $first = ($this->task)($this->audit, 'Collect wage records', ['due_on' => '2026-11-03', 'user_ids' => [$user->id]]);
    ($this->task)($this->audit, 'Fire drill check');
    $this->asToken($this->manager)->postJson(($this->api)("{$this->audit['id']}/tasks/{$first['id']}/status"), ['status' => 'done', 'base_version' => 1])->assertOk();
    $this->asToken($this->manager)->postJson(($this->api)("{$this->audit['id']}/tasks/{$first['id']}/notes"), ['body' => 'Their HR was rude'])->assertCreated();
    ($this->project)($this->rizwan, 'WRAP audit');

    $this->withHeader('Origin', config('app.url'));
});

/** A portal member of the organization, linked to the given clients (as the company's invitation would). */
function projectsPortalMember(Organization $at, array $clients): string
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    app(AddMember::class)->handle($at, $user, MembershipType::Portal, AccessScope::Own);
    $membership = OrganizationMembership::query()->where('user_id', $user->id)->where('organization_id', $at->id)->sole();
    foreach ($clients as $client) {
        (new PortalLink)->forceFill([
            'organization_id' => $at->id, 'membership_id' => $membership->id, 'user_id' => $user->id,
            'subject_type' => 'projects.client', 'subject_id' => $client, 'relation' => 'client', 'status' => PortalLink::ACTIVE, 'linked_via' => 'invitation',
        ])->save();
    }

    return orgToken($user, $at);
}

it('shows a client their own projects with progress, and nothing internal', function () {
    $client = projectsPortalMember($this->w->c1, [$this->rider]);

    $data = $this->asToken($client)->getJson('/api/portal/projects')->assertOk()->json('data');
    expect($data['clients'])->toHaveCount(1)
        ->and($data['clients'][0]['name'])->toBe('Rider Group')
        ->and($data['clients'][0]['projects'])->toHaveCount(1);
    $project = $data['clients'][0]['projects'][0];
    expect($project)->toMatchArray(['name' => 'BSCI renewal', 'status' => 'planned', 'due_on' => '2026-11-05', 'late' => true, 'tasks_total' => 2, 'tasks_done' => 1])
        ->and(array_column($project['tasks'], 'title'))->toBe(['Collect wage records', 'Fire drill check'])
        ->and($project['tasks'][0])->toMatchArray(['status' => 'done', 'due_on' => '2026-11-03']);
    expect(json_encode($data))->not->toContain('WRAP audit')->not->toContain('Internal')->not->toContain('rude')
        ->not->toContain($this->managerId)->not->toContain('people')->not->toContain('version');

    // Another client's projects, asked for by id: the same not found.
    $this->asToken($client)->getJson('/api/portal/projects?client='.$this->rizwan)->assertNotFound()->assertJsonPath('code', 'portal_client_not_found');
    // The staff API stays closed to portal members; staff get nothing from the portal API.
    $this->asToken($client)->getJson(($this->api)())->assertForbidden();
    expect($this->asToken($this->manager)->getJson('/api/portal/projects')->json('data.clients'))->toBe([]);
});

it('shows only how many tasks are done when the company\'s rule hides task titles', function () {
    orgRule($this->w->c1, 'projects.portal_shows_tasks', false);
    $client = projectsPortalMember($this->w->c1, [$this->rider]);

    $project = $this->asToken($client)->getJson('/api/portal/projects?client='.$this->rider)->assertOk()->json('data.clients.0.projects.0');
    expect($project)->toMatchArray(['tasks_total' => 2, 'tasks_done' => 1, 'tasks' => null])
        ->and(json_encode($project))->not->toContain('Fire drill');
});

it('opens the projects from the portal home, and lets the company find clients to share', function () {
    $client = projectsPortalMember($this->w->c1, [$this->rider]);
    $home = $this->asToken($client)->getJson('/api/portal')->assertOk()->json('data.records');
    expect($home)->toHaveCount(1)
        ->and($home[0])->toMatchArray(['name' => 'Rider Group', 'page' => "/portal/projects/{$this->rider}"]);

    $kind = app(PortalSubjects::class)->provider('projects.client');
    expect($kind->relations())->toBe(['client'])
        ->and(array_map(fn ($subject) => $subject->name, $kind->search($this->w->c1, 'Ri')))->toBe(['Rider Group', 'Rizwan Traders'])
        ->and($kind->find($this->w->b1, $this->rider)?->name)->toBe('Rider Group')
        // Another company's CRM, another partner's, or a group: not found.
        ->and($kind->find($this->w->c2, $this->rider))->toBeNull()
        ->and($kind->find($this->w->c4, $this->rider))->toBeNull()
        ->and($kind->find($this->w->g1, $this->rider))->toBeNull();
});

it('refuses the portal projects while Projects or the portal is off, and has nothing to link without CRM', function () {
    $client = projectsPortalMember($this->w->c1, [$this->rider]);
    toggles()->disable($this->w->c1, 'projects', 'Test');
    $this->asToken($client)->getJson('/api/portal/projects')->assertForbidden();

    toggles()->enable($this->w->c1, 'projects', 'Test');
    toggles()->disable($this->w->c1, 'client_portal', 'Test');
    $this->asToken($client)->getJson('/api/portal/projects')->assertForbidden();

    toggles()->enable($this->w->c1, 'client_portal', 'Test');
    toggles()->disable($this->w->c1, 'crm', 'Test');
    app()->forgetScopedInstances();
    expect(app(PortalSubjects::class)->provider('projects.client')->find($this->w->c1, $this->rider))->toBeNull();
    expect($this->asToken($client)->getJson('/api/portal/projects')->assertOk()->json('data.clients'))->toBe([]);
});

it('never shows another tenant\'s projects, even through a link made by hand', function () {
    // A link in C4 (another partner) naming C1's client: C4's CRM has no such contact.
    $stranger = projectsPortalMember($this->w->c4, [$this->rider]);
    expect($this->asToken($stranger)->getJson('/api/portal/projects')->assertOk()->json('data.clients'))->toBe([]);
    // A portal member of C2 (same group) linked to C1's client: nothing either.
    $neighbour = projectsPortalMember($this->w->c2, [$this->rider]);
    expect($this->asToken($neighbour)->getJson('/api/portal/projects')->json('data.clients'))->toBe([]);
});
