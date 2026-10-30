<?php

namespace Modules\Projects\Http;

use App\Models\User;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\Task;
use Modules\Projects\Models\TaskAssignee;
use Modules\Projects\Models\TaskNote;
use Modules\Projects\Models\Template;
use Modules\Projects\Models\TemplateTask;
use Modules\Projects\Services\ProjectBook;

/**
 * API shapes of projects, tasks and templates. Names (people, units) are
 * read once per request; what the person may do is worked out here for
 * the screens (each endpoint checks again).
 */
class ProjectsPresenter
{
    /** @var array<string, Organization|null> */
    private array $units = [];

    /** @var array<string, string> */
    private array $names = [];

    /**
     * Remember people's names for a list.
     *
     * @param  list<string|null>  $ids
     */
    public function loadNames(array $ids): void
    {
        $missing = array_values(array_diff(array_unique(array_filter($ids)), array_keys($this->names)));
        if ($missing !== []) {
            $this->names += User::query()->whereKey($missing)->pluck('name', 'id')->all();
        }
    }

    /**
     * @param  array{total?: int, done?: int, overdue?: int}  $counts
     * @return array<string, mixed>
     */
    public function project(Project $project, array $counts, CarbonImmutable $today): array
    {
        $unit = $this->unit($project->organization_id);
        $writes = $unit !== null && app(CurrentContext::class)->canWriteTo($unit->getKey());
        $manages = $writes && Gate::allows('projects.manage', $unit);
        $this->loadNames([$project->manager_id]);

        return [
            'id' => $project->getKey(),
            'code' => $project->code,
            'name' => $project->name,
            'unit' => ['id' => $project->organization_id, 'name' => $unit?->displayName(), 'type' => $unit?->type->value],
            'client' => ['contact_id' => $project->client_contact_id, 'name' => $project->client_name],
            'service' => $project->service,
            'manager' => $project->manager_id === null ? null : ['id' => $project->manager_id, 'name' => $this->names[$project->manager_id] ?? null],
            'status' => $project->status,
            'status_reason' => $project->status_reason,
            'starts_on' => $project->starts_on?->toDateString(),
            'due_on' => $project->due_on?->toDateString(),
            'finished_on' => $project->finished_on?->toDateString(),
            // Past its due day and still open.
            'late' => $project->isOpen() && $project->due_on !== null && $project->due_on->lt($today),
            'description' => $project->description,
            'tasks' => ['total' => $counts['total'] ?? 0, 'done' => $counts['done'] ?? 0, 'overdue' => $counts['overdue'] ?? 0],
            'version' => $project->version,
            'can' => [
                'edit' => $manages && $project->isOpen(),
                'tasks' => $manages && $project->isOpen(),
                'steps' => $manages ? $this->steps($project) : [],
            ],
        ];
    }

    /**
     * @param  Collection<int, TaskAssignee>  $people
     * @return array<string, mixed>
     */
    public function task(Task $task, Collection $people, Project $project, CarbonImmutable $today, ?User $viewer): array
    {
        $unit = $this->unit($task->organization_id);
        $writes = $unit !== null && app(CurrentContext::class)->canWriteTo($unit->getKey()) && $project->isOpen();
        $manages = $writes && Gate::allows('projects.manage', $unit);
        $mine = $viewer !== null && $people->contains('user_id', $viewer->getKey());
        $this->loadNames([...$people->pluck('user_id')->all(), $task->done_by]);

        return [
            'id' => $task->getKey(),
            'project_id' => $task->project_id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'priority' => $task->priority,
            'due_on' => $task->due_on?->toDateString(),
            'overdue' => $task->status !== Task::DONE && $task->due_on !== null && $task->due_on->lt($today),
            'sort_order' => $task->sort_order,
            'people' => $people->map(fn (TaskAssignee $person) => ['id' => $person->user_id, 'name' => $this->names[$person->user_id] ?? null, 'lead' => $person->is_lead])->values(),
            'mine' => $mine,
            'done_at' => $task->done_at?->toIso8601String(),
            'done_by' => $task->done_by === null ? null : ($this->names[$task->done_by] ?? null),
            'version' => $task->version,
            'can' => [
                'edit' => $manages,
                'assign' => $manages,
                // Its people (projects.work) or a manager.
                'move' => $manages || ($writes && $mine && Gate::allows('projects.work', $unit)),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function note(TaskNote $note): array
    {
        $this->loadNames([$note->user_id]);

        return ['id' => $note->getKey(), 'body' => $note->body, 'by' => $this->names[$note->user_id] ?? null, 'at' => $note->created_at?->toIso8601String()];
    }

    /**
     * @param  Collection<int, TemplateTask>|null  $steps
     * @return array<string, mixed>
     */
    public function template(Template $template, Organization $at, ?Collection $steps = null): array
    {
        $own = $template->organization_id === $at->getKey();

        return [
            'id' => $template->getKey(),
            'name' => $template->textIn('name'),
            'names' => $template->texts('name'),
            'service' => $template->service,
            'is_active' => $template->is_active,
            'own' => $own,
            'kept_by' => $own ? null : $this->unit($template->organization_id)?->displayName(),
            'version' => $template->version,
            'steps' => $steps?->map(fn (TemplateTask $step) => [
                'title' => $step->textIn('title'), 'titles' => $step->texts('title'), 'due_after_days' => $step->due_after_days,
            ])->values(),
        ];
    }

    /**
     * The steps a manager may take from the project's status.
     *
     * @return list<string>
     */
    private function steps(Project $project): array
    {
        return array_keys(array_filter(ProjectBook::STEPS, fn (array $step) => in_array($project->status, $step[0], true)));
    }

    private function unit(string $id): ?Organization
    {
        if (! array_key_exists($id, $this->units)) {
            $this->units[$id] = Organization::query()->find($id);
        }

        return $this->units[$id];
    }
}
