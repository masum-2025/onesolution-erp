<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\Task;

/*
 * PRJ-1: projects for clients, on their own (no business connection). The
 * service company C1 (with branch B1) of group G1 runs projects for its
 * clients (CRM contacts tagged "client", or a name when CRM is off), from
 * templates the group keeps; tasks are for one or more people; the people
 * on a task move it and add notes; managers run everything else.
 */

beforeEach(function () {
    // Wednesday 28 October 2026, 20:00 UTC: already Thursday the 29th in Dhaka.
    $this->travelTo(CarbonImmutable::parse('2026-10-28 20:00:00', 'UTC'));
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        setPlan($group, 'business');
        toggles()->enable($group, 'projects', 'Test setup');
        toggles()->enable($group, 'crm', 'Test setup');
    }
    $person = function (Organization $at, array $permissions, string $name) {
        $user = createMember($at, MembershipType::Staff, AccessScope::Descendants);
        giveRoles($user, $at, makeRole($at, $permissions, $name));

        return $user;
    };
    $this->managerUser = $person($this->w->c1, ['projects.view', 'projects.manage', 'projects.work'], 'Project manager');
    $this->manager = orgToken($this->managerUser, $this->w->c1);
    $this->ayesha = $person($this->w->c1, ['projects.view', 'projects.work'], 'Auditor');
    $this->karim = $person($this->w->c1, ['projects.view', 'projects.work'], 'Auditor 2');
    $this->viewer = orgToken($person($this->w->c1, ['projects.view'], 'Director'), $this->w->c1);
    $this->outsider = $person($this->w->c2, ['projects.view', 'projects.work'], 'Other company');
    $this->groupOwner = orgToken(createMember($this->w->g1), $this->w->g1);
    $this->api = fn (string $path = '', ?Organization $at = null) => '/api/organizations/'.($at ?? $this->w->c1)->id.'/projects'.($path === '' ? '' : "/{$path}");
    $this->as = fn (\App\Models\User $user, ?Organization $at = null) => $this->asToken(orgToken($user, $at ?? $this->w->c1));

    // The group keeps a template of the usual steps.
    $this->template = fn () => $this->asToken($this->groupOwner)->postJson(($this->api)('templates', $this->w->g1), [
        'name' => ['en' => 'BSCI audit', 'bn' => 'বিএসসিআই অডিট'], 'service' => 'BSCI',
        'steps' => [
            ['title' => ['en' => 'Pre-audit visit', 'bn' => 'প্রাক-অডিট পরিদর্শন'], 'due_after_days' => 0],
            ['title' => ['en' => 'Document review'], 'due_after_days' => 5],
            ['title' => ['en' => 'Audit day'], 'due_after_days' => 10],
        ],
    ])->assertCreated()->json('data');
    $this->open = fn (array $data = []) => $this->asToken($this->manager)->postJson(($this->api)(), [
        'name' => 'Rider Group BSCI 2026', 'client_name' => 'Rider Group', 'starts_on' => '2026-11-01', 'due_on' => '2026-11-30', ...$data,
    ]);
});

it('opens a project for a CRM client from a template, numbered per company and year', function () {
    $template = ($this->template)();
    $made = ($this->open)(['template_id' => $template['id'], 'unit_id' => $this->w->b1->id, 'manager_id' => $this->managerUser->id])
        ->assertCreated()->json('data');

    expect($made)->toMatchArray(['code' => 'PRJ-2026-0001', 'status' => 'planned', 'service' => 'BSCI', 'starts_on' => '2026-11-01'])
        ->and($made['unit']['id'])->toBe($this->w->b1->id)
        ->and($made['client']['name'])->toBe('Rider Group')
        ->and($made['client']['contact_id'])->not->toBeNull()
        ->and($made['manager']['name'])->toBe($this->managerUser->name)
        // The template's steps as tasks, due its days after the start (in the company's language: Bangla in Dhaka, else English).
        ->and(collect($made['task_list'])->map(fn ($task) => [$task['title'], $task['due_on']])->all())->toBe([
            ['প্রাক-অডিট পরিদর্শন', '2026-11-01'], ['Document review', '2026-11-06'], ['Audit day', '2026-11-11'],
        ])
        ->and($made['tasks'])->toMatchArray(['total' => 3, 'done' => 0]);

    // The client was added to CRM as a client: found next time, and the next number follows.
    $clients = $this->asToken($this->manager)->getJson(($this->api)('clients').'?q=Rider')->assertOk()->json('data');
    expect($clients)->toHaveCount(1);
    ($this->open)(['name' => 'Rider Group WRAP', 'client_name' => null, 'client_contact_id' => $clients[0]['id']])
        ->assertCreated()->assertJsonPath('data.code', 'PRJ-2026-0002')->assertJsonPath('data.client.name', 'Rider Group');

    // Another company numbers its own; the prefix is a rule.
    trustedOrgRule($this->w->g1, 'projects.number_prefix', 'JOB');
    $this->asToken(orgToken(createMember($this->w->c2), $this->w->c2))->postJson(($this->api)('', $this->w->c2), ['name' => 'Other', 'client_name' => 'X', 'starts_on' => '2026-11-01'])
        ->assertCreated()->assertJsonPath('data.code', 'JOB-2026-0001');

    $this->asToken($this->manager)->getJson(($this->api)())->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.summary.planned', 2);
    expect(AuditLog::query()->where('action', 'projects.project_opened')->count())->toBe(3);
});

it('works without CRM: the client is a name', function () {
    toggles()->disable($this->w->g1, 'crm', 'Test');
    $this->asToken($this->manager)->getJson(($this->api)('options'))->assertOk()->assertJsonPath('data.uses_crm', false);
    ($this->open)()->assertCreated()->assertJsonPath('data.client.contact_id', null)->assertJsonPath('data.client.name', 'Rider Group');
    ($this->open)(['client_name' => null, 'client_contact_id' => (string) Str::ulid()])->assertUnprocessable()->assertJsonPath('code', 'client_unknown');
    ($this->open)(['client_name' => '  '])->assertUnprocessable();
    ($this->open)(['due_on' => '2026-10-01'])->assertUnprocessable()->assertJsonPath('code', 'dates_order');
    ($this->open)(['manager_id' => $this->outsider->id])->assertUnprocessable()->assertJsonPath('code', 'not_assignable');
    ($this->open)(['budget' => 5])->assertUnprocessable();
});

it('gives a task to several people; they move it and add notes, others cannot', function () {
    $project = ($this->open)()->assertCreated()->json('data');
    $task = $this->asToken($this->manager)->postJson(($this->api)("{$project['id']}/tasks"), [
        'title' => 'Collect wage records', 'due_on' => '2026-11-05', 'priority' => 'high', 'user_ids' => [$this->ayesha->id, $this->karim->id], 'lead_id' => $this->ayesha->id, 'op_id' => 'off-1',
    ])->assertCreated()->json('data');
    expect($task['people'])->toHaveCount(2)->and($task['people'][0])->toMatchArray(['id' => $this->ayesha->id, 'lead' => true]);
    // Made offline and sent twice: the same task.
    $this->asToken($this->manager)->postJson(($this->api)("{$project['id']}/tasks"), ['title' => 'Collect wage records', 'op_id' => 'off-1'])
        ->assertCreated()->assertJsonPath('data.id', $task['id']);
    // Both told; not the manager who gave it.
    expect(NotificationDelivery::query()->where('notification_key', 'projects.task_assigned')->pluck('user_id')->all())
        ->toEqualCanonicalizing([$this->ayesha->id, $this->karim->id]);

    // Its people move it and write notes.
    ($this->as)($this->ayesha)->postJson(($this->api)("{$project['id']}/tasks/{$task['id']}/status"), ['status' => 'doing', 'base_version' => 1])
        ->assertOk()->assertJsonPath('data.status', 'doing')->assertJsonPath('data.can.move', true)->assertJsonPath('data.can.edit', false);
    ($this->as)($this->karim)->postJson(($this->api)("{$project['id']}/tasks/{$task['id']}/notes"), ['body' => 'Asked the HR desk.'])->assertCreated();
    // A stale version: someone moved it meanwhile.
    ($this->as)($this->karim)->postJson(($this->api)("{$project['id']}/tasks/{$task['id']}/status"), ['status' => 'blocked', 'base_version' => 1])
        ->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    // Someone not on it, and a viewer: no.
    $this->asToken($this->viewer)->postJson(($this->api)("{$project['id']}/tasks/{$task['id']}/status"), ['status' => 'done', 'base_version' => 2])->assertForbidden();
    // Only a manager changes its details or its people.
    ($this->as)($this->ayesha)->patchJson(($this->api)("{$project['id']}/tasks/{$task['id']}"), ['title' => 'New title', 'base_version' => 2])->assertForbidden();

    // People: only those who work on projects here, the lead among them, within the rule.
    $people = fn (array $ids, ?string $lead = null, int $version = 2) => $this->asToken($this->manager)->putJson(($this->api)("{$project['id']}/tasks/{$task['id']}/people"), ['user_ids' => $ids, 'lead_id' => $lead, 'base_version' => $version]);
    $people([$this->outsider->id])->assertUnprocessable()->assertJsonPath('code', 'not_assignable');
    $people([$this->karim->id], $this->ayesha->id)->assertUnprocessable()->assertJsonPath('code', 'not_assignable');
    trustedOrgRule($this->w->g1, 'projects.max_assignees', 1);
    $people([$this->karim->id, $this->ayesha->id])->assertUnprocessable()->assertJsonPath('code', 'too_many_assignees');
    $people([$this->karim->id], $this->karim->id)->assertOk()->assertJsonCount(1, 'data.people');

    // Ayesha is off it now: no more changes by her, not in her list; Karim's list has it with its project.
    ($this->as)($this->ayesha)->postJson(($this->api)("{$project['id']}/tasks/{$task['id']}/status"), ['status' => 'done', 'base_version' => 3])->assertForbidden();
    ($this->as)($this->ayesha)->getJson(($this->api)('my-tasks'))->assertOk()->assertJsonCount(0, 'data');
    ($this->as)($this->karim)->getJson(($this->api)('my-tasks'))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.project.code', $project['code'])->assertJsonPath('data.0.mine', true);
    ($this->as)($this->karim)->postJson(($this->api)("{$project['id']}/tasks/{$task['id']}/status"), ['status' => 'done', 'base_version' => 3])
        ->assertOk()->assertJsonPath('data.done_by', $this->karim->name);
    ($this->as)($this->karim)->getJson(($this->api)("{$project['id']}/tasks/{$task['id']}/notes"))->assertOk()->assertJsonPath('data.0.body', 'Asked the HR desk.');
    expect(AuditLog::query()->where('action', 'projects.task_assigned')->exists())->toBeTrue();
});

it('moves a project through its steps; tasks change only while it is open', function () {
    $project = ($this->open)()->json('data');
    $step = fn (string $name, int $version, ?string $reason = null) => $this->asToken($this->manager)->postJson(($this->api)("{$project['id']}/{$name}"), array_filter(['base_version' => $version, 'reason' => $reason]));
    $step('resume', 1)->assertStatus(409)->assertJsonPath('code', 'wrong_step');
    $step('start', 1)->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.can.steps', ['hold', 'finish', 'cancel']);
    $step('hold', 2)->assertUnprocessable()->assertJsonValidationErrors('reason');
    $step('hold', 2, 'Client asked to wait')->assertOk()->assertJsonPath('data.status_reason', 'Client asked to wait');
    $one = $this->asToken($this->manager)->postJson(($this->api)("{$project['id']}/tasks"), ['title' => 'First'])->assertCreated()->json('data.id');
    $two = $this->asToken($this->manager)->postJson(($this->api)("{$project['id']}/tasks"), ['title' => 'Second'])->assertCreated()->json('data.id');
    $this->asToken($this->manager)->postJson(($this->api)("{$project['id']}/tasks/order"), ['ids' => [$two, $one]])->assertOk()->assertJson(['data' => [$two, $one]]);
    $step('finish', 3)->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.finished_on', '2026-10-29');
    $this->asToken($this->manager)->postJson(($this->api)("{$project['id']}/tasks"), ['title' => 'Late'])->assertStatus(409)->assertJsonPath('code', 'project_closed');
    $step('reopen', 4, 'One more visit')->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.finished_on', null);
    expect(AuditLog::query()->where('action', 'projects.project_hold')->value('reason'))->toBe('Client asked to wait');
});

it('tells a task\'s people once when it passes its due day', function () {
    $project = ($this->open)()->json('data');
    $this->asToken($this->manager)->postJson(($this->api)("{$project['id']}/tasks"), ['title' => 'Send the plan', 'due_on' => '2026-11-02', 'user_ids' => [$this->ayesha->id, $this->karim->id]])->assertCreated();
    $this->asToken($this->manager)->postJson(($this->api)("{$project['id']}/tasks"), ['title' => 'Nobody yet', 'due_on' => '2026-11-02'])->assertCreated();
    NotificationDelivery::query()->delete();

    $this->travelTo(CarbonImmutable::parse('2026-11-03 06:00:00', 'UTC'));
    Artisan::call('projects:overdue-tasks');
    Artisan::call('projects:overdue-tasks');
    expect(NotificationDelivery::query()->where('notification_key', 'projects.task_overdue')->pluck('user_id')->all())
        ->toEqualCanonicalizing([$this->ayesha->id, $this->karim->id]);
    // In the bell and on the project.
    $attention = collect(($this->as)($this->ayesha)->getJson('/api/attention')->assertOk()->json('data'))->firstWhere('key', 'projects.overdue');
    expect($attention['count'])->toBe(1);
    ($this->as)($this->managerUser)->getJson(($this->api)($project['id']))->assertJsonPath('data.tasks.overdue', 2);

    // Switched off by the rule: nobody is told.
    trustedOrgRule($this->w->g1, 'projects.overdue_alert', false);
    Task::query()->withoutGlobalScope(OrganizationScope::class)->update(['overdue_told_for' => null]);
    NotificationDelivery::query()->delete();
    Artisan::call('projects:overdue-tasks');
    expect(NotificationDelivery::query()->count())->toBe(0);
});

it('keeps projects to the right people, units and tenants', function () {
    $project = ($this->open)()->json('data');
    // The group reads its companies' projects but opens none for them.
    $this->asToken($this->groupOwner)->getJson(($this->api)('', $this->w->g1))->assertOk()->assertJsonCount(1, 'data');
    $this->asToken($this->groupOwner)->postJson(($this->api)('', $this->w->g1), ['unit_id' => $this->w->c1->id, 'name' => 'Xyz project', 'client_name' => 'Y', 'starts_on' => '2026-11-01'])
        ->assertForbidden()->assertJsonPath('code', 'write_forbidden');
    // A viewer reads only; someone with only projects.work has their own list but not the projects.
    $this->asToken($this->viewer)->postJson(($this->api)(), ['name' => 'Xyz project', 'client_name' => 'Y', 'starts_on' => '2026-11-01'])->assertForbidden();
    $worker = createMember($this->w->c1, MembershipType::Staff, AccessScope::Descendants);
    giveRoles($worker, $this->w->c1, makeRole($this->w->c1, ['projects.work'], 'Helper'));
    ($this->as)($worker)->getJson(($this->api)('my-tasks'))->assertOk();
    ($this->as)($worker)->getJson(($this->api)($project['id']))->assertForbidden();

    // Templates: kept by a group or a company with projects.configure; one kept above is changed there.
    $template = ($this->template)();
    $this->asToken($this->manager)->postJson(($this->api)('templates'), ['name' => ['en' => 'T'], 'steps' => [['title' => ['en' => 'S'], 'due_after_days' => 0]]])->assertForbidden();
    $c1Owner = orgToken(createMember($this->w->c1), $this->w->c1);
    $this->asToken($c1Owner)->patchJson(($this->api)("templates/{$template['id']}"), ['base_version' => 1, 'name' => ['en' => 'Mine'], 'steps' => [['title' => ['en' => 'S'], 'due_after_days' => 0]]])
        ->assertUnprocessable()->assertJsonPath('code', 'not_template_owner');
    $this->asToken(orgToken(createMember($this->w->b1), $this->w->b1))->postJson(($this->api)('templates', $this->w->b1), ['name' => ['en' => 'T'], 'steps' => [['title' => ['en' => 'S'], 'due_after_days' => 0]]])
        ->assertUnprocessable()->assertJsonPath('code', 'not_template_owner');

    // Another tenant: not found; its own people cannot be given tasks here.
    $other = orgToken(createMember($this->w->c4), $this->w->c4);
    $this->asToken($other)->getJson(($this->api)($project['id']))->assertNotFound();
    $this->asToken($other)->getJson(($this->api)($project['id'], $this->w->c4))->assertNotFound()->assertJsonPath('code', 'project_not_found');

    // The module off: closed, nothing deleted.
    toggles()->disable($this->w->g1, 'projects', 'Test');
    $this->asToken($this->manager)->getJson(($this->api)())->assertForbidden();
    expect(Project::query()->withoutGlobalScope(OrganizationScope::class)->count())->toBe(1);
});

it('works for a client kept in its own database', function () {
    dedicatedTenantDatabase();
    placeClient($this->w->g1);
    $template = ($this->template)();
    $project = ($this->open)(['template_id' => $template['id']])->assertCreated()->json('data');
    $this->asToken($this->manager)->postJson(($this->api)("{$project['id']}/tasks"), ['title' => 'Extra', 'user_ids' => [$this->ayesha->id]])->assertCreated();

    expect(Task::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->where('project_id', $project['id'])->count())->toBe(4);
    ($this->as)($this->ayesha)->getJson(($this->api)('my-tasks'))->assertOk()->assertJsonCount(1, 'data');
});
