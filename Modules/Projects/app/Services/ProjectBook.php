<?php

namespace Modules\Projects\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Projects\Exceptions\ProjectsException;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\Sequence;
use Modules\Projects\Models\Task;

/**
 * Projects for clients (PRJ-1). A company or a branch opens one for a
 * client, numbered per company and year (prefix: rule
 * projects.number_prefix), optionally from a template whose steps become
 * its tasks (due the template's days after the start). It moves planned →
 * active ⇄ on hold → done, or is cancelled; done and cancelled ones may be
 * reopened (with a reason). Never deleted; every change audited.
 */
class ProjectBook
{
    /** step => [from statuses, to status, reason needed]. */
    public const STEPS = [
        'start' => [[Project::PLANNED], Project::ACTIVE, false],
        'hold' => [[Project::ACTIVE], Project::ON_HOLD, true],
        'resume' => [[Project::ON_HOLD], Project::ACTIVE, false],
        'finish' => [[Project::ACTIVE, Project::ON_HOLD], Project::DONE, false],
        'cancel' => [[Project::PLANNED, Project::ACTIVE, Project::ON_HOLD], Project::CANCELLED, true],
        'reopen' => [[Project::DONE, Project::CANCELLED], Project::ACTIVE, true],
    ];

    public function __construct(
        private Workplace $place,
        private Templates $templates,
        private Clients $clients,
        private People $people,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private OrganizationSettingsResolver $settings,
        private AuditLogger $audit,
    ) {}

    /**
     * Projects at the unit and below, newest first.
     *
     * @param  array{status?: string|null, manager_id?: string|null, client_contact_id?: string|null, q?: string|null}  $filters
     * @return Collection<int, Project>
     */
    public function list(Organization $unit, array $filters = [], int $limit = 500): Collection
    {
        $needle = trim((string) ($filters['q'] ?? ''));

        return $this->place->query(Project::class, $unit, $this->place->subtreeIds($unit))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['manager_id'] ?? null, fn ($query, $manager) => $query->where('manager_id', $manager))
            ->when($filters['client_contact_id'] ?? null, fn ($query, $client) => $query->where('client_contact_id', $client))
            ->when($needle !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', '%'.addcslashes($needle, '%_\\').'%')
                ->orWhere('code', 'like', '%'.addcslashes($needle, '%_\\').'%')
                ->orWhere('client_name', 'like', '%'.addcslashes($needle, '%_\\').'%')))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
    }

    public function find(Organization $unit, string $id): Project
    {
        return $this->place->query(Project::class, $unit, $this->place->subtreeIds($unit))->whereKey($id)->first()
            ?? throw ProjectsException::projectNotFound();
    }

    /**
     * Open a project at a company or branch, with its template's tasks.
     *
     * @param  array{name: string, client_contact_id?: string|null, client_name?: string|null, template_id?: string|null, service?: string|null, manager_id?: string|null, starts_on: string, due_on?: string|null, description?: string|null}  $data
     */
    public function open(Organization $unit, array $data, User $actor): Project
    {
        $this->place->assertWorkplace($unit);
        $company = $this->place->companyOf($unit);
        $this->checkDates($data['starts_on'], $data['due_on'] ?? null);
        $this->checkManager($unit, $data['manager_id'] ?? null);
        $template = empty($data['template_id']) ? null : $this->templates->find($unit, (string) $data['template_id']);
        $steps = $template === null ? collect() : $this->templates->steps($template);
        $max = (int) $this->rules->get('projects.max_tasks', $this->contexts->forOrganization($unit));
        if ($steps->count() > $max) {
            throw ProjectsException::tooManyTasks($max);
        }
        $client = $this->clients->resolve($company, $unit, $data['client_contact_id'] ?? null, $data['client_name'] ?? null, $actor);
        $locale = (string) ($this->settings->values($company)['default_locale'] ?? app()->getLocale());

        return $this->place->transaction($unit, function () use ($unit, $company, $data, $template, $steps, $client, $locale, $actor) {
            $project = new Project([
                ...array_intersect_key($data, array_flip(['name', 'service', 'manager_id', 'starts_on', 'due_on', 'description'])),
                ...$client,
                'service' => $data['service'] ?? ($template?->service),
                'organization_id' => $unit->getKey(),
                'company_id' => $company->getKey(),
                'code' => $this->nextCode($unit, $company, $data['starts_on']),
                'template_id' => $template?->getKey(),
                'status' => Project::PLANNED,
                'created_by' => $actor->getKey(),
                'version' => 1,
            ]);
            $project->save();

            $start = CarbonImmutable::parse($data['starts_on'], 'UTC');
            foreach ($steps->values() as $index => $step) {
                Task::inTenantOf($unit)->withoutGlobalScope(OrganizationScope::class)->create([
                    'organization_id' => $unit->getKey(),
                    'project_id' => $project->getKey(),
                    'title' => mb_substr($step->textIn('title', $locale), 0, 200),
                    'status' => Task::TODO,
                    'priority' => 'normal',
                    'due_on' => $start->addDays($step->due_after_days)->toDateString(),
                    'sort_order' => $index + 1,
                    'created_by' => $actor->getKey(),
                    'version' => 1,
                ]);
            }

            $this->audit->record('projects.project_opened', $project, new: [...$this->values($project), 'tasks' => $steps->count()],
                actor: $actor, organizationId: $unit->getKey());

            return $project;
        });
    }

    /**
     * Name, client, service, manager, days, description.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Organization $unit, Project $project, int $baseVersion, array $data, User $actor): Project
    {
        $at = Organization::query()->findOrFail($project->organization_id);
        $company = Organization::query()->findOrFail($project->company_id);
        if (array_key_exists('manager_id', $data)) {
            $this->checkManager($at, $data['manager_id']);
        }
        $this->checkDates($data['starts_on'] ?? $project->starts_on->toDateString(), array_key_exists('due_on', $data) ? $data['due_on'] : $project->due_on?->toDateString());
        $client = array_key_exists('client_contact_id', $data) || array_key_exists('client_name', $data)
            ? $this->clients->resolve($company, $at, $data['client_contact_id'] ?? null, $data['client_name'] ?? null, $actor)
            : [];

        return $this->place->transaction($unit, function () use ($project, $baseVersion, $data, $client, $actor) {
            $project = $this->locked($project, $baseVersion);
            $old = $this->values($project);
            $project->fill([...array_intersect_key($data, array_flip(['name', 'service', 'manager_id', 'starts_on', 'due_on', 'description'])), ...$client]);
            if (! $project->isDirty()) {
                return $project;
            }
            $project->version++;
            $project->save();
            $this->audit->record('projects.project_changed', $project, old: $old, new: $this->values($project), actor: $actor, organizationId: $project->organization_id);

            return $project;
        });
    }

    /** start, hold, resume, finish, cancel or reopen (hold, cancel and reopen with a reason). */
    public function step(Organization $unit, Project $project, string $step, int $baseVersion, ?string $reason, User $actor): Project
    {
        [$from, $to, $needsReason] = self::STEPS[$step];

        return $this->place->transaction($unit, function () use ($project, $step, $from, $to, $needsReason, $baseVersion, $reason, $actor) {
            $project = $this->locked($project, $baseVersion);
            if (! in_array($project->status, $from, true)) {
                throw ProjectsException::wrongStep($project->status);
            }
            $old = $project->status;
            $company = Organization::query()->findOrFail($project->company_id);
            $project->forceFill([
                'status' => $to,
                'status_reason' => $needsReason ? $reason : null,
                'finished_on' => $to === Project::DONE ? $this->place->today($company)->toDateString() : ($to === Project::ACTIVE ? null : $project->finished_on?->toDateString()),
                'version' => $project->version + 1,
            ])->save();
            $this->audit->record("projects.project_{$step}", $project, old: ['status' => $old], new: ['status' => $to], reason: $reason,
                actor: $actor, organizationId: $project->organization_id);

            return $project;
        });
    }

    private function nextCode(Organization $unit, Organization $company, string $on): string
    {
        $year = (int) substr($on, 0, 4);
        $sequence = $this->place->query(Sequence::class, $unit, [$company->getKey()])->where('year', $year)->lockForUpdate()->first();
        if ($sequence === null) {
            $sequence = Sequence::inTenantOf($unit)->withoutGlobalScope(OrganizationScope::class)->make();
            $sequence->fill(['organization_id' => $company->getKey(), 'year' => $year, 'last_number' => 0]);
        }
        $sequence->last_number++;
        $sequence->save();
        $prefix = (string) $this->rules->get('projects.number_prefix', $this->contexts->forOrganization($company));

        return sprintf('%s-%d-%04d', $prefix, $year, $sequence->last_number);
    }

    private function checkDates(string $startsOn, ?string $dueOn): void
    {
        if ($dueOn !== null && $dueOn < $startsOn) {
            throw ProjectsException::datesOrder();
        }
    }

    private function checkManager(Organization $unit, ?string $managerId): void
    {
        if ($managerId !== null && ! $this->people->allAssignable($unit, [$managerId])) {
            throw ProjectsException::notAssignable();
        }
    }

    private function locked(Project $project, int $baseVersion): Project
    {
        /** @var Project $fresh */
        $fresh = $this->place->query(Project::class, Organization::query()->findOrFail($project->organization_id), [$project->organization_id])
            ->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw ProjectsException::versionConflict();
        }

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Project $project): array
    {
        return [
            ...$project->only(['code', 'name', 'client_contact_id', 'client_name', 'service', 'manager_id', 'status']),
            'starts_on' => $project->starts_on?->toDateString(),
            'due_on' => $project->due_on?->toDateString(),
        ];
    }
}
