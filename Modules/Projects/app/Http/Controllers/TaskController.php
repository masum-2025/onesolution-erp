<?php

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Exceptions\OrganizationAccessDenied;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Projects\Exceptions\ProjectsException;
use Modules\Projects\Http\Controllers\Concerns\FindsWorkplace;
use Modules\Projects\Http\ProjectsPresenter;
use Modules\Projects\Http\Requests\NoteRequest;
use Modules\Projects\Http\Requests\OrderRequest;
use Modules\Projects\Http\Requests\PeopleRequest;
use Modules\Projects\Http\Requests\TaskRequest;
use Modules\Projects\Http\Requests\TaskStatusRequest;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\Task;
use Modules\Projects\Services\ProjectBook;
use Modules\Projects\Services\Tasks;
use Modules\Projects\Services\Workplace;

/**
 * A project's tasks. projects.manage adds, changes, orders and assigns
 * them; the people a task is for (projects.work) change its status and
 * add notes. "My tasks": everything the signed-in person is on.
 */
class TaskController extends Controller
{
    use FindsWorkplace;

    public function __construct(
        private Workplace $place,
        private ProjectBook $projects,
        private Tasks $tasks,
        private ProjectsPresenter $presenter,
    ) {}

    /** The person's own tasks at the unit and below (not done unless asked), with their projects. */
    public function mine(Request $request, string $organization): JsonResponse
    {
        $unit = $this->unitWithAny($organization, ['projects.work', 'projects.view']);
        $withDone = $request->boolean('done');
        $tasks = $this->tasks->mine($unit, $request->user(), $withDone);
        $projects = $this->place->query(Project::class, $unit, $this->place->subtreeIds($unit))->whereKey($tasks->pluck('project_id')->unique()->all())->get()->keyBy('id');
        $people = collect();
        foreach ($tasks->groupBy('organization_id') as $organizationId => $group) {
            $people = $people->union($this->tasks->assigneesOf($organizationId, $group->modelKeys()));
        }
        $todays = [];

        return response()->json(['data' => $tasks->map(function (Task $task) use ($projects, $people, $request, &$todays) {
            $project = $projects[$task->project_id];
            $today = $todays[$project->company_id] ??= $this->place->today(Organization::query()->findOrFail($project->company_id));

            return [
                ...$this->presenter->task($task, $people->get($task->getKey(), collect()), $project, $today, $request->user()),
                'project' => ['id' => $project->getKey(), 'code' => $project->code, 'name' => $project->name, 'client' => $project->client_name],
            ];
        })->values()]);
    }

    public function store(TaskRequest $request, string $organization, string $project): JsonResponse
    {
        [$found] = $this->managed($organization, $project);
        $task = $this->tasks->add($found, $request->validated(), $request->user());

        return response()->json(['data' => $this->shaped($found, $task, $request->user())], 201);
    }

    public function update(TaskRequest $request, string $organization, string $project, string $task): JsonResponse
    {
        [$found] = $this->managed($organization, $project);
        $changed = $this->tasks->update($found, $this->tasks->find($found, $task), (int) $request->validated('base_version'), $request->validated(), $request->user());

        return response()->json(['data' => $this->shaped($found, $changed, $request->user())]);
    }

    public function status(TaskStatusRequest $request, string $organization, string $project, string $task): JsonResponse
    {
        [$found, $item] = $this->workedOn($organization, $project, $task, $request->user());
        $moved = $this->tasks->move($found, $item, $request->validated('status'), (int) $request->validated('base_version'), $request->user());

        return response()->json(['data' => $this->shaped($found, $moved, $request->user())]);
    }

    public function people(PeopleRequest $request, string $organization, string $project, string $task): JsonResponse
    {
        [$found] = $this->managed($organization, $project);
        $assigned = $this->tasks->assign($found, $this->tasks->find($found, $task), (int) $request->validated('base_version'),
            $request->validated('user_ids'), $request->validated('lead_id'), $request->user());

        return response()->json(['data' => $this->shaped($found, $assigned, $request->user())]);
    }

    public function order(OrderRequest $request, string $organization, string $project): JsonResponse
    {
        [$found] = $this->managed($organization, $project);
        $this->tasks->reorder($found, $request->validated('ids'), $request->user());

        return response()->json(['data' => $this->tasks->of($found)->pluck('id')->values()]);
    }

    public function notes(string $organization, string $project, string $task): JsonResponse
    {
        $unit = $this->unitWithAny($organization, ['projects.view', 'projects.work']);
        $found = $this->projects->find($unit, $project);
        $item = $this->tasks->find($found, $task);
        // Without projects.view: only the notes of one's own tasks.
        if (! Gate::allows('projects.view', $unit) && ! $this->tasks->isOn($item, request()->user())) {
            throw ProjectsException::notYourTask();
        }

        return response()->json(['data' => $this->tasks->notes($item)->map(fn ($note) => $this->presenter->note($note))->values()]);
    }

    public function addNote(NoteRequest $request, string $organization, string $project, string $task): JsonResponse
    {
        [$found, $item] = $this->workedOn($organization, $project, $task, $request->user());
        $note = $this->tasks->note($found, $item, $request->validated('body'), $request->user());

        return response()->json(['data' => $this->presenter->note($note)], 201);
    }

    /**
     * A project the person manages, written from here.
     *
     * @return array{0: Project}
     */
    private function managed(string $organization, string $project): array
    {
        $unit = $this->unit($organization, 'projects.view');
        $found = $this->projects->find($unit, $project);
        $this->writable($found->organization_id, 'projects.manage');

        return [$found];
    }

    /**
     * A task the person manages, or is on with projects.work.
     *
     * @return array{0: Project, 1: Task}
     */
    private function workedOn(string $organization, string $project, string $task, User $user): array
    {
        $unit = $this->unitWithAny($organization, ['projects.manage', 'projects.work']);
        $found = $this->projects->find($unit, $project);
        $item = $this->tasks->find($found, $task);
        $at = Organization::query()->findOrFail($found->organization_id);
        if (! app(CurrentContext::class)->canWriteTo($at->getKey())) {
            throw OrganizationAccessDenied::writeOutsideScope();
        }
        $manages = Gate::allows('projects.manage', $at);
        if (! $manages && ! (Gate::allows('projects.work', $at) && $this->tasks->isOn($item, $user))) {
            throw ProjectsException::notYourTask();
        }

        return [$found, $item];
    }

    /**
     * @return array<string, mixed>
     */
    private function shaped(Project $project, Task $task, User $viewer): array
    {
        $people = $this->tasks->assigneesOf($task->organization_id, [$task->getKey()])->get($task->getKey(), collect());
        $today = $this->place->today(Organization::query()->findOrFail($project->company_id));

        return $this->presenter->task($task, $people, $this->projects->find(Organization::query()->findOrFail($project->organization_id), $project->getKey()), $today, $viewer);
    }
}
