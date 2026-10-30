<?php

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Projects\Http\Controllers\Concerns\FindsWorkplace;
use Modules\Projects\Http\ProjectsPresenter;
use Modules\Projects\Http\Requests\ProjectRequest;
use Modules\Projects\Http\Requests\StepRequest;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\Task;
use Modules\Projects\Services\Clients;
use Modules\Projects\Services\People;
use Modules\Projects\Services\ProjectBook;
use Modules\Projects\Services\Tasks;
use Modules\Projects\Services\Templates;
use Modules\Projects\Services\Workplace;

/**
 * Projects at the unit in the address and below. Reading needs
 * projects.view; opening and changing projects projects.manage at the
 * company or branch that runs them (a group reads only).
 */
class ProjectController extends Controller
{
    use FindsWorkplace;

    /** @var array<string, CarbonImmutable> */
    private array $todays = [];

    public function __construct(
        private Workplace $place,
        private ProjectBook $projects,
        private Tasks $tasks,
        private Templates $templates,
        private People $people,
        private Clients $clients,
        private ProjectsPresenter $presenter,
    ) {}

    /** What the screens choose from here, and what the person may do. */
    public function options(string $organization): JsonResponse
    {
        $unit = $this->unitWithAny($organization, ['projects.view', 'projects.work']);
        $writes = in_array($unit->type->value, ['company', 'branch'], true) && app(CurrentContext::class)->canWriteTo($unit->getKey());
        $company = $unit->type->value === 'group' ? null : $this->place->companyOf($unit);

        return response()->json(['data' => [
            'statuses' => Project::STATUSES,
            'task_statuses' => Task::STATUSES,
            'priorities' => Task::PRIORITIES,
            'templates' => $this->templates->usableAt($unit)->map(fn ($template) => $this->presenter->template($template, $unit))->values(),
            'people' => $this->people->assignable($unit)->map(fn (User $user) => ['id' => $user->getKey(), 'name' => $user->name])->values(),
            'uses_crm' => $company !== null && $this->clients->usesCrm($company),
            'today' => $company === null ? CarbonImmutable::now('UTC')->toDateString() : $this->place->today($company)->toDateString(),
            'can' => [
                'open' => $writes && Gate::allows('projects.manage', $unit),
                'templates' => in_array($unit->type->value, ['group', 'company'], true) && Gate::allows('projects.configure', $unit),
                'view' => Gate::allows('projects.view', $unit),
            ],
            'reads_only' => ! $writes,
        ]]);
    }

    /** The company's clients in CRM, by name. */
    public function clients(Request $request, string $organization): JsonResponse
    {
        $unit = $this->unit($organization, 'projects.manage');
        $query = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $company = $unit->type->value === 'group' ? null : $this->place->companyOf($unit);

        return response()->json(['data' => $company === null ? [] : $this->clients->search($company, $query['q'] ?? null)]);
    }

    public function index(Request $request, string $organization): JsonResponse
    {
        $unit = $this->unit($organization, 'projects.view');
        $filters = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', Project::STATUSES)],
            'manager_id' => ['nullable', 'string', 'size:26'],
            'client_contact_id' => ['nullable', 'string', 'size:26'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $projects = $this->projects->list($unit, $filters);
        $counts = $this->counts($unit, $projects);

        return response()->json([
            'data' => $projects->map(fn (Project $project) => $this->presenter->project($project, $counts[$project->getKey()] ?? [], $this->todayOf($project->company_id)))->values(),
            'meta' => ['summary' => collect(Project::STATUSES)->mapWithKeys(fn (string $status) => [$status => $projects->where('status', $status)->count()])],
        ]);
    }

    public function store(ProjectRequest $request, string $organization): JsonResponse
    {
        $unit = $this->unit($organization, 'projects.view');
        $at = $this->unitInside($unit, $request->validated('unit_id'));
        $this->writable($at->getKey(), 'projects.manage');
        $project = $this->projects->open($at, $request->validated(), $request->user());

        return response()->json(['data' => $this->detail($unit, $project, $request->user())], 201);
    }

    public function show(Request $request, string $organization, string $project): JsonResponse
    {
        $unit = $this->unit($organization, 'projects.view');

        return response()->json(['data' => $this->detail($unit, $this->projects->find($unit, $project), $request->user())]);
    }

    public function update(ProjectRequest $request, string $organization, string $project): JsonResponse
    {
        $unit = $this->unit($organization, 'projects.view');
        $found = $this->projects->find($unit, $project);
        $this->writable($found->organization_id, 'projects.manage');
        $changed = $this->projects->update($unit, $found, (int) $request->validated('base_version'), $request->validated(), $request->user());

        return response()->json(['data' => $this->detail($unit, $changed, $request->user())]);
    }

    public function step(StepRequest $request, string $organization, string $project, string $step): JsonResponse
    {
        abort_unless(array_key_exists($step, ProjectBook::STEPS), 404);
        $unit = $this->unit($organization, 'projects.view');
        $found = $this->projects->find($unit, $project);
        $this->writable($found->organization_id, 'projects.manage');
        $done = $this->projects->step($unit, $found, $step, (int) $request->validated('base_version'), $request->validated('reason'), $request->user());

        return response()->json(['data' => $this->detail($unit, $done, $request->user())]);
    }

    /**
     * The project with its tasks and their people.
     *
     * @return array<string, mixed>
     */
    private function detail(Organization $unit, Project $project, User $viewer): array
    {
        $today = $this->todayOf($project->company_id);
        $tasks = $this->tasks->of($project);
        $people = $this->tasks->assigneesOf($project->organization_id, $tasks->modelKeys());
        $counts = $this->counts($unit, collect([$project]));

        return [
            ...$this->presenter->project($project, $counts[$project->getKey()] ?? [], $today),
            'task_list' => $tasks->map(fn (Task $task) => $this->presenter->task($task, $people->get($task->getKey(), collect()), $project, $today, $viewer))->values(),
        ];
    }

    /**
     * Tasks of each project: how many, done and overdue (read once for a list).
     *
     * @param  Collection<int, Project>  $projects
     * @return array<string, array{total: int, done: int, overdue: int}>
     */
    private function counts(Organization $unit, Collection $projects): array
    {
        if ($projects->isEmpty()) {
            return [];
        }
        $byId = $projects->keyBy('id');
        $rows = Task::inTenantOf($unit)->withoutGlobalScope(OrganizationScope::class)->whereIn('project_id', $byId->keys()->all())
            ->get(['project_id', 'status', 'due_on']);

        return $rows->groupBy('project_id')->map(function (Collection $tasks, string $projectId) use ($byId) {
            $today = $this->todayOf($byId[$projectId]->company_id);

            return [
                'total' => $tasks->count(),
                'done' => $tasks->where('status', Task::DONE)->count(),
                'overdue' => $tasks->filter(fn (Task $task) => $task->status !== Task::DONE && $task->due_on !== null && $task->due_on->lt($today))->count(),
            ];
        })->all();
    }

    private function todayOf(string $companyId): CarbonImmutable
    {
        return $this->todays[$companyId] ??= $this->place->today(Organization::query()->findOrFail($companyId));
    }
}
