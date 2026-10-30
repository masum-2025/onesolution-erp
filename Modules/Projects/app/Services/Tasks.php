<?php

namespace Modules\Projects\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Projects\Exceptions\ProjectsException;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\Task;
use Modules\Projects\Models\TaskAssignee;
use Modules\Projects\Models\TaskNote;

/**
 * A project's tasks, each for one or more people (one may lead it). People
 * who manage projects add, change, order and assign tasks; the people a
 * task is for change its status and add notes. Tasks change only while
 * the project is open. Changes carry the version seen; a task made offline
 * carries an op_id (the same op_id again is the same task).
 */
class Tasks
{
    public function __construct(
        private Workplace $place,
        private People $people,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * @return Collection<int, Task>
     */
    public function of(Project $project): Collection
    {
        return $this->query($project->organization_id)->where('project_id', $project->getKey())
            ->orderBy('sort_order')->orderBy('created_at')->get();
    }

    /**
     * The people of each task, lead first.
     *
     * @param  list<string>  $taskIds
     * @return Collection<string, Collection<int, TaskAssignee>>
     */
    public function assigneesOf(string $organizationId, array $taskIds): Collection
    {
        if ($taskIds === []) {
            return collect();
        }

        return TaskAssignee::inTenantOf($organizationId)->withoutGlobalScope(OrganizationScope::class)->whereIn('task_id', $taskIds)
            ->orderByDesc('is_lead')->orderBy('created_at')->get()->groupBy('task_id');
    }

    /**
     * The person's tasks at the unit and below, not done, in open projects: overdue and soonest first.
     *
     * @return Collection<int, Task>
     */
    public function mine(Organization $unit, User $user, bool $withDone = false): Collection
    {
        $units = $this->place->subtreeIds($unit);
        $projects = $this->place->query(Project::class, $unit, $units)->whereIn('status', Project::OPEN)->select('id');

        return $this->place->query(Task::class, $unit, $units)
            ->whereIn('project_id', $projects)
            ->whereIn('id', TaskAssignee::inTenantOf($unit)->withoutGlobalScope(OrganizationScope::class)->where('user_id', $user->getKey())->select('task_id'))
            ->when(! $withDone, fn ($query) => $query->where('status', '!=', Task::DONE))
            ->get()
            ->sortBy(fn (Task $task) => [$task->due_on === null ? 1 : 0, $task->due_on?->toDateString() ?? '', $task->sort_order])
            ->values();
    }

    public function find(Project $project, string $id): Task
    {
        return $this->query($project->organization_id)->where('project_id', $project->getKey())->whereKey($id)->first()
            ?? throw ProjectsException::taskNotFound();
    }

    /** Whether the person is one of the task's people. */
    public function isOn(Task $task, User $user): bool
    {
        return TaskAssignee::inTenantOf($task->organization_id)->withoutGlobalScope(OrganizationScope::class)
            ->where('task_id', $task->getKey())->where('user_id', $user->getKey())->exists();
    }

    /**
     * @param  array{title: string, description?: string|null, priority?: string, due_on?: string|null, user_ids?: list<string>, lead_id?: string|null, op_id?: string|null}  $data
     */
    public function add(Project $project, array $data, User $actor): Task
    {
        $unit = Organization::query()->findOrFail($project->organization_id);
        $userIds = array_values(array_unique($data['user_ids'] ?? []));
        $this->checkPeople($unit, $userIds, $data['lead_id'] ?? null);

        $task = $this->place->transaction($unit, function () use ($unit, $project, $data, $userIds, $actor) {
            $project = $this->openProject($project);
            if (! empty($data['op_id'])) {
                $existing = $this->query($unit->getKey())->where('project_id', $project->getKey())->where('op_id', $data['op_id'])->first();
                if ($existing !== null) {
                    return [$existing, []];
                }
            }
            $max = (int) $this->rules->get('projects.max_tasks', $this->contexts->forOrganization($unit));
            $count = $this->query($unit->getKey())->where('project_id', $project->getKey())->count();
            if ($count >= $max) {
                throw ProjectsException::tooManyTasks($max);
            }
            $task = $this->query($unit->getKey())->create([
                'organization_id' => $unit->getKey(),
                'project_id' => $project->getKey(),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => Task::TODO,
                'priority' => $data['priority'] ?? 'normal',
                'due_on' => $data['due_on'] ?? null,
                'sort_order' => (int) $this->query($unit->getKey())->where('project_id', $project->getKey())->max('sort_order') + 1,
                'op_id' => $data['op_id'] ?? null,
                'created_by' => $actor->getKey(),
                'version' => 1,
            ]);
            $added = $this->writePeople($task, $userIds, $data['lead_id'] ?? null, $actor);
            $this->audit->record('projects.task_added', $task, new: [...$this->values($task), 'people' => $userIds], actor: $actor, organizationId: $unit->getKey());

            return [$task, $added];
        });

        [$made, $added] = $task;
        $this->tellAssigned($project, $made, $added, $actor);

        return $made;
    }

    /**
     * Title, description, priority, due day.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Project $project, Task $task, int $baseVersion, array $data, User $actor): Task
    {
        $unit = Organization::query()->findOrFail($project->organization_id);

        return $this->place->transaction($unit, function () use ($project, $task, $baseVersion, $data, $actor) {
            $this->openProject($project);
            $task = $this->locked($task, $baseVersion);
            $old = $this->values($task);
            $task->fill(array_intersect_key($data, array_flip(['title', 'description', 'priority', 'due_on'])));
            if (! $task->isDirty()) {
                return $task;
            }
            if ($task->isDirty('due_on')) {
                // A new due day: the overdue notice may come again for it.
                $task->overdue_told_for = null;
            }
            $task->version++;
            $task->save();
            $this->audit->record('projects.task_changed', $task, old: $old, new: $this->values($task), actor: $actor, organizationId: $task->organization_id);

            return $task;
        });
    }

    /** To do, doing, blocked or done: by its people or a manager. */
    public function move(Project $project, Task $task, string $status, int $baseVersion, User $actor): Task
    {
        $unit = Organization::query()->findOrFail($project->organization_id);

        return $this->place->transaction($unit, function () use ($project, $task, $status, $baseVersion, $actor) {
            $this->openProject($project);
            $task = $this->locked($task, $baseVersion);
            if ($task->status === $status) {
                return $task;
            }
            $old = $task->status;
            $task->forceFill([
                'status' => $status,
                'done_at' => $status === Task::DONE ? now() : null,
                'done_by' => $status === Task::DONE ? $actor->getKey() : null,
                'version' => $task->version + 1,
            ])->save();
            $this->audit->record('projects.task_moved', $task, old: ['status' => $old], new: ['status' => $status], actor: $actor, organizationId: $task->organization_id);

            return $task;
        });
    }

    /**
     * The task's people, all of them (one may lead): those added are told.
     *
     * @param  list<string>  $userIds
     */
    public function assign(Project $project, Task $task, int $baseVersion, array $userIds, ?string $leadId, User $actor): Task
    {
        $unit = Organization::query()->findOrFail($project->organization_id);
        $userIds = array_values(array_unique($userIds));
        $this->checkPeople($unit, $userIds, $leadId);

        [$task, $added] = $this->place->transaction($unit, function () use ($project, $task, $baseVersion, $userIds, $leadId, $actor) {
            $this->openProject($project);
            $task = $this->locked($task, $baseVersion);
            $before = $this->assigneesOf($task->organization_id, [$task->getKey()])->get($task->getKey(), collect())->pluck('user_id')->all();
            $added = $this->writePeople($task, $userIds, $leadId, $actor);
            $task->forceFill(['version' => $task->version + 1])->save();
            $this->audit->record('projects.task_assigned', $task, old: ['people' => $before], new: ['people' => $userIds, 'lead' => $leadId],
                actor: $actor, organizationId: $task->organization_id);

            return [$task, $added];
        });
        $this->tellAssigned($project, $task, $added, $actor);

        return $task;
    }

    /**
     * The order of the project's tasks (the ids given first, in that order).
     *
     * @param  list<string>  $ids
     */
    public function reorder(Project $project, array $ids, User $actor): void
    {
        $unit = Organization::query()->findOrFail($project->organization_id);
        $this->place->transaction($unit, function () use ($project, $ids, $actor) {
            $this->openProject($project);
            $tasks = $this->query($project->organization_id)->where('project_id', $project->getKey())->lockForUpdate()->get()->keyBy('id');
            $order = array_values(array_unique([...array_filter($ids, fn ($id) => $tasks->has($id)), ...$tasks->sortBy('sort_order')->keys()->all()]));
            foreach ($order as $index => $id) {
                if ($tasks[$id]->sort_order !== $index + 1) {
                    $tasks[$id]->forceFill(['sort_order' => $index + 1])->save();
                }
            }
            $this->audit->record('projects.tasks_ordered', $project, new: ['order' => $order], actor: $actor, organizationId: $project->organization_id);
        });
    }

    public function note(Project $project, Task $task, string $body, User $actor): TaskNote
    {
        $this->openProject($project);

        return TaskNote::inTenantOf($task->organization_id)->withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $task->organization_id,
            'task_id' => $task->getKey(),
            'user_id' => $actor->getKey(),
            'body' => $body,
            'created_at' => now(),
        ]);
    }

    /**
     * @return Collection<int, TaskNote>
     */
    public function notes(Task $task): Collection
    {
        return TaskNote::inTenantOf($task->organization_id)->withoutGlobalScope(OrganizationScope::class)->where('task_id', $task->getKey())
            ->orderBy('created_at')->orderBy('id')->get();
    }

    /**
     * Tell the people of each task past its due day (rule projects.overdue_alert),
     * once per due day, in a company's open projects. Returns how many tasks.
     */
    public function tellOverdue(Organization $company, ?CarbonImmutable $now = null): int
    {
        if ($this->rules->get('projects.overdue_alert', $this->contexts->forOrganization($company)) !== true) {
            return 0;
        }
        $today = $this->place->today($company, $now)->toDateString();
        $projects = Project::query()->withoutGlobalScope(OrganizationScope::class)->where('company_id', $company->getKey())->whereIn('status', Project::OPEN)->get()->keyBy('id');
        $tasks = Task::query()->withoutGlobalScope(OrganizationScope::class)->whereIn('project_id', $projects->keys()->all())
            ->where('status', '!=', Task::DONE)->whereNotNull('due_on')->whereDate('due_on', '<', $today)->get()
            ->filter(fn (Task $task) => $task->overdue_told_for?->toDateString() !== $task->due_on->toDateString());
        $people = $this->assigneesOf($company->getKey(), $tasks->modelKeys());
        $told = 0;
        foreach ($tasks as $task) {
            $task->forceFill(['overdue_told_for' => $task->due_on->toDateString()])->save();
            $users = User::query()->whereKey($people->get($task->getKey(), collect())->pluck('user_id')->all())->get();
            if ($users->isEmpty()) {
                continue;
            }
            $project = $projects[$task->project_id];
            $this->notifier->notify('projects.task_overdue', $users, fn (string $locale) => [
                'organization' => $company->displayName($locale),
                'project' => "{$project->code} {$project->name}",
                'task' => $task->title,
                'due' => $task->due_on->locale($locale)->isoFormat('LL'),
            ], $company->partner, $company);
            $told++;
        }

        return $told;
    }

    /**
     * @param  list<string>  $userIds
     */
    private function checkPeople(Organization $unit, array $userIds, ?string $leadId): void
    {
        $max = (int) $this->rules->get('projects.max_assignees', $this->contexts->forOrganization($unit));
        if (count($userIds) > $max) {
            throw ProjectsException::tooManyAssignees($max);
        }
        if (($leadId !== null && ! in_array($leadId, $userIds, true)) || ! $this->people->allAssignable($unit, $userIds)) {
            throw ProjectsException::notAssignable();
        }
    }

    /**
     * Replace the task's people; returns the ids added now.
     *
     * @param  list<string>  $userIds
     * @return list<string>
     */
    private function writePeople(Task $task, array $userIds, ?string $leadId, User $actor): array
    {
        $rows = TaskAssignee::inTenantOf($task->organization_id)->withoutGlobalScope(OrganizationScope::class)->where('task_id', $task->getKey())->get()->keyBy('user_id');
        foreach ($rows as $userId => $row) {
            if (! in_array($userId, $userIds, true)) {
                $row->delete();
            } elseif ($row->is_lead !== ($userId === $leadId)) {
                $row->forceFill(['is_lead' => $userId === $leadId])->save();
            }
        }
        $added = array_values(array_diff($userIds, $rows->keys()->all()));
        foreach ($added as $userId) {
            TaskAssignee::inTenantOf($task->organization_id)->withoutGlobalScope(OrganizationScope::class)->create([
                'organization_id' => $task->organization_id,
                'task_id' => $task->getKey(),
                'user_id' => $userId,
                'is_lead' => $userId === $leadId,
                'assigned_by' => $actor->getKey(),
                'created_at' => now(),
            ]);
        }

        return $added;
    }

    /**
     * @param  list<string>  $added
     */
    private function tellAssigned(Project $project, Task $task, array $added, User $actor): void
    {
        // Not the person who gave it to themselves.
        $users = User::query()->whereKey(array_values(array_diff($added, [$actor->getKey()])))->get();
        if ($users->isEmpty()) {
            return;
        }
        $company = Organization::query()->findOrFail($project->company_id);
        $this->notifier->notify('projects.task_assigned', $users, fn (string $locale) => [
            'organization' => $company->displayName($locale),
            'project' => "{$project->code} {$project->name}",
            'task' => $task->title,
            'due' => $task->due_on?->locale($locale)->isoFormat('LL') ?? __('projects::projects.no_due', [], $locale),
            'by' => (string) $actor->name,
        ], $company->partner, $company);
    }

    private function openProject(Project $project): Project
    {
        $fresh = $this->place->query(Project::class, Organization::query()->findOrFail($project->organization_id), [$project->organization_id])
            ->whereKey($project->getKey())->firstOrFail();
        if (! $fresh->isOpen()) {
            throw ProjectsException::projectClosed();
        }

        return $fresh;
    }

    private function locked(Task $task, int $baseVersion): Task
    {
        /** @var Task $fresh */
        $fresh = $this->query($task->organization_id)->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw ProjectsException::versionConflict();
        }

        return $fresh;
    }

    /**
     * @return Builder<Task>
     */
    private function query(string $organizationId): Builder
    {
        return Task::inTenantOf($organizationId)->withoutGlobalScope(OrganizationScope::class)->where('organization_id', $organizationId);
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Task $task): array
    {
        return [...$task->only(['title', 'priority', 'status']), 'due_on' => $task->due_on?->toDateString()];
    }
}
