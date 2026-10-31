<?php

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Portal\PortalAccess;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Exceptions\ProjectsException;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\Task;
use Modules\Projects\Portal\ClientSubjects;
use Modules\Projects\Services\Clients;
use Modules\Projects\Services\Workplace;

/**
 * A client's projects in the company's portal (B2B2C): only those of the
 * clients the company linked to the member, read only. Status, dates, tasks
 * done of all and (by rule) the task titles with their status and due day;
 * never the people, notes, descriptions, money or other clients. Anything
 * else is the same 404.
 */
class PortalProjectController extends Controller
{
    public function __construct(
        private Workplace $place,
        private Clients $clients,
        private PortalAccess $portal,
        private CurrentContext $context,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $request->validate(['client' => ['nullable', 'string', 'size:26']]);
        $organization = $this->context->organization();
        $company = $this->companyOf($organization);
        $linked = $company === null || ! $this->portal->isPortal() ? [] : $this->portal->subjectIds(ClientSubjects::KEY);
        if ($request->filled('client')) {
            $linked = array_values(array_intersect($linked, [$request->string('client')->toString()]));
            if ($linked === []) {
                throw ProjectsException::portalClientNotFound();
            }
        }
        $contacts = $company === null ? [] : $this->clients->many($company, $linked);

        $units = $this->place->subtreeIds($organization);
        $projects = $contacts === [] ? collect() : $this->place->query(Project::class, $organization, $units)
            ->where('company_id', $company->getKey())->whereIn('client_contact_id', array_keys($contacts))
            ->orderByDesc('starts_on')->orderBy('code')->limit(100)->get()
            // Open ones first, newest start first within each.
            ->sortBy(fn (Project $project) => in_array($project->status, Project::OPEN, true) ? 0 : 1)->values();
        $tasks = $projects->isEmpty() ? collect() : $this->place->query(Task::class, $organization, $units)
            ->whereIn('project_id', $projects->modelKeys())->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'project_id', 'title', 'status', 'due_on'])->groupBy('project_id');
        $titles = (bool) $this->rules->get('projects.portal_shows_tasks', $this->contexts->forOrganization($organization));
        $today = $company === null ? null : $this->place->today($company)->toDateString();

        return response()->json(['data' => [
            'organization' => $organization->displayName(),
            'clients' => collect($contacts)->map(fn (array $contact) => [
                'id' => $contact['id'],
                'name' => $contact['name'],
                'projects' => $projects->where('client_contact_id', $contact['id'])->map(function (Project $project) use ($tasks, $titles, $today) {
                    $mine = $tasks->get($project->getKey(), collect());

                    return [
                        'id' => $project->getKey(),
                        'code' => $project->code,
                        'name' => $project->name,
                        'service' => $project->service,
                        'status' => $project->status,
                        'starts_on' => $project->starts_on?->toDateString(),
                        'due_on' => $project->due_on?->toDateString(),
                        'finished_on' => $project->finished_on?->toDateString(),
                        'late' => in_array($project->status, Project::OPEN, true) && $project->due_on !== null && $project->due_on->toDateString() < $today,
                        'tasks_total' => $mine->count(),
                        'tasks_done' => $mine->where('status', Task::DONE)->count(),
                        'tasks' => ! $titles ? null : $mine->map(fn (Task $task) => [
                            'id' => $task->getKey(),
                            'title' => $task->title,
                            'status' => $task->status,
                            'due_on' => $task->due_on?->toDateString(),
                        ])->values(),
                    ];
                })->values(),
            ])->values(),
        ]]);
    }

    /** The company whose clients these are, when it keeps them in CRM. */
    private function companyOf(Organization $organization): ?Organization
    {
        if (! in_array($organization->type, [OrganizationType::Company, OrganizationType::Branch], true)) {
            return null;
        }
        $company = $this->place->companyOf($organization);

        return $this->clients->usesCrm($company) ? $company : null;
    }
}
